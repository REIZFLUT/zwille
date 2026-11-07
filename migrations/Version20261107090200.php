<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Time window and click limit for a link, plus a fallback target.
 *
 * StartsAt schedules a link (null = immediately live), maxClicks bounds the number of
 * redirects (null = unlimited) and fallbackUrl is where a visitor of an unavailable link is
 * sent instead of the notice page. All three are nullable, so existing rows keep behaving as
 * before.
 *
 * Written with the schema API: one run yields the SQLite and MySQL/MariaDB DDL.
 */
final class Version20261107090200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Time window and click limit: link.starts_at, link.max_clicks, link.fallback_url';
    }

    public function up(Schema $schema): void
    {
        $link = $schema->getTable('link');
        $link->addColumn('starts_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $link->addColumn('max_clicks', Types::INTEGER, ['notnull' => false]);
        $link->addColumn('fallback_url', Types::TEXT, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $link = $schema->getTable('link');
        $link->dropColumn('starts_at');
        $link->dropColumn('max_clicks');
        $link->dropColumn('fallback_url');
    }
}
