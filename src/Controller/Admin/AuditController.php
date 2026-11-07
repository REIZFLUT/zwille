<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Repository\AuditRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ADMIN_ACCESS')]
#[Route('/admin/audit')]
final class AuditController extends AbstractController
{
    private const int PER_PAGE = 50;

    #[Route('', name: 'app_admin_audit', methods: ['GET'])]
    public function index(Request $request, AuditRepository $audit): Response
    {
        $action = (string) $request->query->get('action', '');
        $action = \in_array($action, AuditLogger::KNOWN_ACTIONS, true) ? $action : '';
        $actor = trim((string) $request->query->get('actor', ''));
        $from = self::day($request->query->get('from'), false);
        $to = self::day($request->query->get('to'), true);
        $page = max(1, $request->query->getInt('page', 1));

        $query = $audit->filteredQuery('' === $action ? null : $action, '' === $actor ? null : $actor, $from, $to)
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE);
        $paginator = new Paginator($query, fetchJoinCollection: false);
        $total = \count($paginator);

        return $this->render('admin/audit/index.html.twig', [
            'entries' => $paginator,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'actions' => AuditLogger::KNOWN_ACTIONS,
            'action' => $action,
            'actor' => $actor,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Parses an ISO date from the filter. "to" is widened to the end of the day so the
     * chosen day is included; an invalid value counts as "no filter".
     */
    private static function day(mixed $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0);
    }
}
