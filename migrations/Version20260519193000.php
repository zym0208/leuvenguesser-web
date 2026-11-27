<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add role storage for user and admin accounts.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('roles')) {
            $this->addSql('ALTER TABLE Users ADD roles JSON NULL AFTER recent_games');
        }

        $this->addSql("UPDATE Users SET roles = JSON_ARRAY('ROLE_USER') WHERE roles IS NULL");
        $this->addSql('ALTER TABLE Users MODIFY roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($this->hasColumn('roles')) {
            $this->addSql('ALTER TABLE Users DROP COLUMN roles');
        }
    }

    private function hasColumn(string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['Users', $column]
        );
    }
}
