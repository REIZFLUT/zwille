<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit trail table. Described through the schema API like the other migrations, so one
 * run produces valid DDL on SQLite (development) and MySQL/MariaDB (production).
 *
 * No foreign key to app_user on purpose: the trail must survive a user's deletion, so an
 * entry freezes the actor id and the label (e-mail) it had at the time instead of pointing
 * at the live row.
 */
final class Version20261107090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit trail: audit_entry (occurred_at, action, actor, target, context, ip, user agent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('audit_entry');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('action', Types::STRING, ['length' => 64]);
        $table->addColumn('actor_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('actor_label', Types::STRING, ['length' => 180]);
        $table->addColumn('target_type', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('target_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('context', Types::JSON);
        $table->addColumn('ip', Types::STRING, ['length' => 45, 'notnull' => false]);
        $table->addColumn('user_agent', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['occurred_at'], 'idx_audit_occurred');
        $table->addIndex(['action'], 'idx_audit_action');
        $table->addIndex(['actor_id'], 'idx_audit_actor');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('audit_entry');
    }
}
