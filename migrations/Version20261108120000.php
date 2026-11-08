<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename to Zwille: the settings form stores every field, so many installs carry the old
 * default title "EatMyShort.Link" explicitly. Only exactly that value is switched to the
 * new default; a title an admin chose stays untouched.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename to Zwille: replace the stored former default title "EatMyShort.Link"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE app_setting SET setting_value = ? WHERE setting_key = ? AND setting_value = ?',
            ['Zwille', 'app.title', 'EatMyShort.Link'],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE app_setting SET setting_value = ? WHERE setting_key = ? AND setting_value = ?',
            ['EatMyShort.Link', 'app.title', 'Zwille'],
        );
    }
}
