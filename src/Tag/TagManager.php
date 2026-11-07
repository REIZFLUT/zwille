<?php

declare(strict_types=1);

namespace App\Tag;

use App\Entity\Link;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes and name resolution for tags, shared by the tag page, the link form, the bulk
 * actions and the API. Normalisation only ever happens in Tag::setName(), so every entry
 * point compares the same key.
 */
final readonly class TagManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private TagRepository $tags,
    ) {
    }

    public function create(User $owner, string $name): Tag
    {
        $tag = new Tag($owner, $name);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    public function rename(Tag $tag, string $name): void
    {
        $tag->setName($name);
        $this->em->flush();
    }

    public function delete(Tag $tag): void
    {
        // The join rows go away through the link_tag foreign keys (ON DELETE CASCADE);
        // the links themselves stay untouched.
        $this->em->remove($tag);
        $this->em->flush();
    }

    /**
     * Resolves tag names to entities for $owner, creating the ones that are not known yet.
     * Does not flush: the caller saves the tags together with their link (one flush).
     *
     * @param iterable<string> $names
     *
     * @return list<Tag>
     */
    public function resolve(User $owner, iterable $names): array
    {
        $resolved = [];
        foreach ($names as $raw) {
            $name = trim($raw);
            if ('' === $name) {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($resolved[$key])) {
                continue;
            }
            $tag = $this->tags->findOneByOwnerName($owner, $name);
            if (null === $tag) {
                $tag = new Tag($owner, $name);
                $this->em->persist($tag);
            }
            $resolved[$key] = $tag;
        }

        return array_values($resolved);
    }

    /**
     * Adds or removes one tag on every given link and flushes once - a bulk run over N
     * links must not issue N separate flushes.
     *
     * @param iterable<Link> $links
     *
     * @return int the number of links actually changed
     */
    public function attach(iterable $links, Tag $tag, bool $add): int
    {
        $changed = 0;
        foreach ($links as $link) {
            if ($add) {
                if (!$link->hasTag($tag)) {
                    $link->addTag($tag);
                    ++$changed;
                }
            } elseif ($link->hasTag($tag)) {
                $link->removeTag($tag);
                ++$changed;
            }
        }
        $this->em->flush();

        return $changed;
    }
}
