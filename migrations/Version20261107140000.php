<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agent tokens: a token kind, scopes, optional limits to domains, target hosts and client
 * addresses, and a revocation timestamp. Existing tokens become "personal" tokens with every
 * scope (scopes = NULL), so nothing changes for them.
 *
 * Described through the schema API so the DDL matches the platform (SQLite, MariaDB/MySQL).
 */
final class Version20261107140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agent tokens: kind, scopes, domain/target/IP limits and revocation for api_token';
    }

    public function up(Schema $schema): void
    {
        $token = $schema->getTable('api_token');
        $token->addColumn('kind', Types::STRING, ['length' => 16, 'default' => 'personal']);
        $token->addColumn('scopes', Types::JSON, ['notnull' => false]);
        $token->addColumn('target_hosts', Types::JSON, ['notnull' => false]);
        $token->addColumn('allowed_ips', Types::JSON, ['notnull' => false]);
        $token->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

        $tokenDomain = $schema->createTable('api_token_domain');
        $tokenDomain->addColumn('api_token_id', Types::INTEGER);
        $tokenDomain->addColumn('domain_id', Types::INTEGER);
        $tokenDomain->setPrimaryKey(['api_token_id', 'domain_id']);
        $tokenDomain->addIndex(['api_token_id'], 'IDX_ACBB68A492E52D36');
        $tokenDomain->addIndex(['domain_id'], 'IDX_ACBB68A4115F0EE5');
        $tokenDomain->addForeignKeyConstraint('api_token', ['api_token_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_ACBB68A492E52D36');
        $tokenDomain->addForeignKeyConstraint('domain', ['domain_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_ACBB68A4115F0EE5');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('api_token_domain');

        $token = $schema->getTable('api_token');
        foreach (['kind', 'scopes', 'target_hosts', 'allowed_ips', 'revoked_at'] as $column) {
            $token->dropColumn($column);
        }
    }
}
