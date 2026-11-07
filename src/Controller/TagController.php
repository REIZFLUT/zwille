<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use App\Tag\TagManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Per-user tag management: list, create, inline rename and delete. Never admin-only;
 * every lookup is scoped by owner so a foreign id is answered with a 404.
 */
#[Route('/tags')]
final class TagController extends AbstractController
{
    private const int NAME_MAX = 64;

    public function __construct(
        private readonly TagRepository $tags,
        private readonly TagManager $tagManager,
    ) {
    }

    #[Route('', name: 'app_tags', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('tag/index.html.twig', [
            'tags' => $this->tags->findByOwner($user),
            'totals' => $this->tags->totals($user),
        ]);
    }

    #[Route('/new', name: 'app_tag_new', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('tag-new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = $this->readName($request);
        if (null === $name) {
            $this->addFlash('warning', 'tag.name_invalid');

            return $this->redirectToRoute('app_tags');
        }
        if (null !== $this->tags->findOneByOwnerName($user, $name)) {
            $this->addFlash('warning', 'tag.name_taken');

            return $this->redirectToRoute('app_tags');
        }

        $this->tagManager->create($user, $name);
        $this->addFlash('success', 'tag.created');

        return $this->redirectToRoute('app_tags');
    }

    #[Route('/{id}', name: 'app_tag_rename', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function rename(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $tag = $this->owned($user, $id);
        if (!$this->isCsrfTokenValid('tag-rename-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = $this->readName($request);
        if (null === $name) {
            $this->addFlash('warning', 'tag.name_invalid');

            return $this->redirectToRoute('app_tags');
        }
        $existing = $this->tags->findOneByOwnerName($user, $name);
        if (null !== $existing && $existing->getId() !== $tag->getId()) {
            $this->addFlash('warning', 'tag.name_taken');

            return $this->redirectToRoute('app_tags');
        }

        $this->tagManager->rename($tag, $name);
        $this->addFlash('success', 'tag.renamed');

        return $this->redirectToRoute('app_tags');
    }

    #[Route('/{id}/delete', name: 'app_tag_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $tag = $this->owned($user, $id);
        if (!$this->isCsrfTokenValid('tag-delete-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->tagManager->delete($tag);
        $this->addFlash('success', 'tag.deleted');

        return $this->redirectToRoute('app_tags');
    }

    /** Owner-scoped fetch: a foreign or unknown id becomes a 404, never a bare find(). */
    private function owned(User $user, int $id): Tag
    {
        $tag = $this->tags->findOwned($user, $id);
        if (null === $tag) {
            throw new NotFoundHttpException('Unknown tag.');
        }

        return $tag;
    }

    private function readName(Request $request): ?string
    {
        $name = trim((string) $request->request->get('name'));

        return ('' === $name || mb_strlen($name) > self::NAME_MAX) ? null : $name;
    }
}
