<?php

declare(strict_types=1);

namespace App\Link;

use App\Entity\Link;
use App\Event\Link\LinkDeletedEvent;
use App\Event\Link\LinkPersistingEvent;
use App\Event\Link\LinkSavedEvent;
use App\Settings\Settings;
use App\Validator\LinkPassword;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Shared create/update logic for the web UI and the API.
 */
final readonly class LinkManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
        private ShortCodeGenerator $codes,
        private Settings $settings,
        private LinkTitleFetcher $titleFetcher,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function applyDefaults(Link $link): void
    {
        if (!\in_array($link->getRedirectType(), Link::REDIRECT_TYPES, true) || null === $link->getId()) {
            $link->setRedirectType($this->settings->getInt(Settings::DEFAULT_REDIRECT));
        }
    }

    /** Fills in a random code when none was given. Call before validation. */
    public function prepare(Link $link): void
    {
        if ('' === $link->getCode() && null !== $link->getDomain()) {
            $link->setCode($this->codes->generate($link->getDomain()));
        }
    }

    public function validate(Link $link): ConstraintViolationListInterface
    {
        $this->prepare($link);

        return $this->validator->validate($link);
    }

    /**
     * Applies the same length rules to a plain unlock password that the form's field uses, so
     * the API rejects a too-short or too-long password with the identical message. Returns the
     * (translated) message of the first violation, or null when the password is acceptable.
     */
    public function passwordError(string $plain): ?string
    {
        foreach ($this->validator->validate($plain, new LinkPassword()) as $violation) {
            return (string) $violation->getMessage();
        }

        return null;
    }

    /**
     * Persists the link. Callers validate first, so the title of a new link is fetched only
     * for targets that passed validation (a rejected target, e.g. a loop to one of our own
     * hosts, is never requested). Bulk work such as the CSV import passes $fetchTitle = false:
     * one blocking request per row would run into the PHP time limit.
     */
    public function save(Link $link, bool $fetchTitle = true): void
    {
        $created = null === $link->getId();

        // Plugins can refuse the write before anything is persisted. A rejection carries a
        // message the caller shows (form error in the web UI, 422 in the API), so the caller
        // can exit cleanly instead of hitting a half-written link.
        $persisting = new LinkPersistingEvent($link, $created);
        $this->dispatcher->dispatch($persisting);
        if ($persisting->isRejected()) {
            $message = $persisting->getRejectionMessage();

            throw new LinkRejectedException(null !== $message && '' !== $message ? $message : 'The link was rejected.');
        }

        if ($fetchTitle && $created) {
            $this->fetchTitle($link);
        }
        $link->touch();
        if ($created) {
            $this->em->persist($link);
        }
        $this->em->flush();

        $this->dispatcher->dispatch(new LinkSavedEvent($link, $created));
    }

    public function delete(Link $link): void
    {
        $this->em->remove($link);
        $this->em->flush();

        $this->dispatcher->dispatch(new LinkDeletedEvent($link));
    }

    /**
     * Pulls the page title from the target when enabled – YOURLS-style – but only for a
     * blank title and an http(s) target. Any error inside the fetcher stays contained: a
     * failing fetch must not block link creation.
     */
    private function fetchTitle(Link $link): void
    {
        if (!$this->settings->getBool(Settings::LINK_FETCH_TITLE)) {
            return;
        }
        $title = $link->getTitle();
        if (null !== $title && '' !== trim($title)) {
            return;
        }
        $target = $link->getTargetUrl();
        if (1 !== preg_match('#^https?://#i', $target)) {
            return;
        }

        try {
            $fetched = $this->titleFetcher->fetch($target);
        } catch (\Throwable) {
            return;
        }
        if (null !== $fetched) {
            $link->setTitle($fetched);
        }
    }
}
