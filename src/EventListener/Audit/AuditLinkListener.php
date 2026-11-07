<?php

declare(strict_types=1);

namespace App\EventListener\Audit;

use App\Audit\AuditLogger;
use App\Entity\Link;
use App\Event\Link\LinkDeletedEvent;
use App\Event\Link\LinkSavedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Link lifecycle trail. The web UI, the bulk actions and the REST API all write through
 * LinkManager, so a single pair of listeners on the existing @api events covers every
 * create, update and delete - no audit calls are scattered over the controllers.
 *
 * A bulk deletion of N links runs LinkManager::delete() N times and therefore writes N
 * entries, one per link.
 */
final readonly class AuditLinkListener
{
    public function __construct(private AuditLogger $audit)
    {
    }

    #[AsEventListener(event: LinkSavedEvent::class)]
    public function onSaved(LinkSavedEvent $event): void
    {
        $link = $event->getLink();
        $this->audit->record(
            $event->wasCreated() ? AuditLogger::LINK_CREATED : AuditLogger::LINK_UPDATED,
            self::context($link),
            targetType: 'link',
            targetId: $link->getId(),
        );
    }

    #[AsEventListener(event: LinkDeletedEvent::class)]
    public function onDeleted(LinkDeletedEvent $event): void
    {
        $link = $event->getLink();
        $this->audit->record(
            AuditLogger::LINK_DELETED,
            self::context($link),
            targetType: 'link',
            targetId: $link->getId(),
        );
    }

    /** @return array<string, string> */
    private static function context(Link $link): array
    {
        return [
            'link' => self::shortUrl($link),
            'target' => $link->getTargetUrl(),
        ];
    }

    /**
     * LinkDeletedEvent carries a detached entity (see its docblock), so the domain - a
     * lazy association - may no longer be loadable; fall back to the bare code in that case
     * instead of letting a read failure break the deletion.
     */
    private static function shortUrl(Link $link): string
    {
        try {
            return $link->getShortUrl();
        } catch (\Throwable) {
            return $link->getCode();
        }
    }
}
