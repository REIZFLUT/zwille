<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Overview of every agent token across all users, with revocation of single tokens or all
 * of them at once. The global kill switch lives in the settings.
 */
#[IsGranted('ADMIN_ACCESS')]
#[Route('/admin/agent-tokens')]
final class AgentTokenController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiTokenRepository $tokens,
    ) {
    }

    #[Route('', name: 'app_admin_agent_tokens', methods: ['GET'])]
    public function index(Settings $settings): Response
    {
        return $this->render('admin/agent_token/index.html.twig', [
            'tokens' => $this->tokens->findAgentTokens(),
            'agent_access' => $settings->getBool(Settings::AGENT_ACCESS),
        ]);
    }

    #[Route('/{id}/revoke', name: 'app_admin_agent_token_revoke', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function revoke(Request $request, ApiToken $token): Response
    {
        if (!$token->isAgent() || !$this->isCsrfTokenValid('agent-token-revoke-'.$token->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $token->revoke();
        $this->em->flush();
        $this->addFlash('success', 'flash.token_deleted');

        return $this->redirectToRoute('app_admin_agent_tokens');
    }

    #[Route('/revoke-all', name: 'app_admin_agent_tokens_revoke_all', methods: ['POST'])]
    public function revokeAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('agent-tokens-revoke-all', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $count = $this->tokens->revokeAllAgentTokens();
        $this->addFlash('success', $count > 0 ? 'flash.agent_tokens_revoked' : 'flash.agent_tokens_none');

        return $this->redirectToRoute('app_admin_agent_tokens');
    }
}
