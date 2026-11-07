<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tags and internal notes.
 *
 * Tags are labels per owner; a link carries any number of them through the explicit join
 * table link_tag. Uniqueness is keyed on the normalised name (owner_id, normalized_name)
 * so SQLite (case-sensitive) and MySQL/MariaDB (case-insensitive by default) treat
 * "Sommer" and "sommer" as the same tag.
 *
 * The join table's index and foreign-key names are the ones Doctrine derives from the
 * ManyToMany mapping (App\Entity\Link::$tags); the mapping cannot name them, so they are
 * reproduced verbatim - otherwise doctrine:migrations:diff would report drift.
 *
 * Written with the schema API: one run yields the SQLite and MySQL/MariaDB DDL.
 */
final class Version20261107090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tags and internal notes: tag table plus the link_tag join table';
    }

    public function up(Schema $schema): void
    {
        $tag = $schema->createTable('tag');
        $tag->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $tag->addColumn('name', Types::STRING, ['length' => 64]);
        $tag->addColumn('normalized_name', Types::STRING, ['length' => 64]);
        $tag->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $tag->addColumn('owner_id', Types::INTEGER);
        $tag->setPrimaryKey(['id']);
        $tag->addIndex(['owner_id'], 'idx_tag_owner');
        $tag->addUniqueIndex(['owner_id', 'normalized_name'], 'uniq_tag_owner_name');
        $tag->addForeignKeyConstraint('app_user', ['owner_id'], ['id'], [], 'FK_389B7837E3C61F9');

        // Internal note: nullable text on the link, never serialised to the outside.
        $link = $schema->getTable('link');
        $link->addColumn('notes', Types::TEXT, ['notnull' => false]);

        $linkTag = $schema->createTable('link_tag');
        $linkTag->addColumn('link_id', Types::INTEGER);
        $linkTag->addColumn('tag_id', Types::INTEGER);
        $linkTag->setPrimaryKey(['link_id', 'tag_id']);
        $linkTag->addIndex(['link_id'], 'IDX_4FF23AB8ADA40271');
        $linkTag->addIndex(['tag_id'], 'IDX_4FF23AB8BAD26311');
        $linkTag->addForeignKeyConstraint('link', ['link_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_4FF23AB8ADA40271');
        $linkTag->addForeignKeyConstraint('tag', ['tag_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_4FF23AB8BAD26311');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('link_tag');

        $link = $schema->getTable('link');
        $link->dropColumn('notes');

        $schema->dropTable('tag');
    }
}
