<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Password-protected links and per-device targets.
 *
 * link.password_hash holds a nullable bcrypt hash (null = the link is public). The new
 * link_device_target table stores one optional target per device (ios, android, desktop);
 * the unique key uniq_device_target_link_device guarantees at most one row per link and device,
 * and the foreign key cascades so deleting a link removes its device targets.
 *
 * The index, unique-index and foreign-key names are the ones Doctrine derives from the mapping
 * (App\Entity\LinkDeviceTarget); the mapping can only name the unique index and the plain index,
 * so the foreign key name is reproduced verbatim - otherwise doctrine:migrations:diff reports
 * drift. Written with the schema API: one run yields the SQLite and MySQL/MariaDB DDL.
 */
final class Version20261107090300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Password-protected links: link.password_hash and the link_device_target table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('link_device_target');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('device', Types::STRING, ['length' => 16]);
        $table->addColumn('target_url', Types::TEXT);
        $table->addColumn('link_id', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['link_id', 'device'], 'uniq_device_target_link_device');
        $table->addIndex(['link_id'], 'idx_device_target_link');
        $table->addForeignKeyConstraint('link', ['link_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_E0E752FBADA40271');

        $schema->getTable('link')
            ->addColumn('password_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('link')->dropColumn('password_hash');

        $schema->dropTable('link_device_target');
    }
}
