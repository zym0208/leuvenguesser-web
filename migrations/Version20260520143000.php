<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add score and recent_games columns to Users table.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('score')) {
            $this->addSql('ALTER TABLE Users ADD score INT DEFAULT 0 NOT NULL');
        }

        if (!$this->hasColumn('recent_games')) {
            $this->addSql("ALTER TABLE Users ADD recent_games JSON NOT NULL DEFAULT (JSON_ARRAY())");
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->hasColumn('recent_games')) {
            $this->addSql('ALTER TABLE Users DROP COLUMN recent_games');
        }

        if ($this->hasColumn('score')) {
            $this->addSql('ALTER TABLE Users DROP COLUMN score');
        }
    }

    private function hasColumn(string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?',
            ['Users', $column]
        );
    }
}
