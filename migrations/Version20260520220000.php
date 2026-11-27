<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Use split user score columns, drop ChallengePlays, sync challenge play_count from GameResults.';
    }

    public function up(Schema $schema): void
    {
        if ($this->hasColumn('score') && !$this->hasColumn('leuven_score')) {
            $this->addSql('ALTER TABLE Users ADD leuven_score INT NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE Users ADD challenge_score INT NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE Users ADD duel_score INT NOT NULL DEFAULT 0');
            $this->addSql('UPDATE Users SET leuven_score = score');
            $this->addSql('ALTER TABLE Users DROP COLUMN score');
        }

        if ($this->hasColumn('recent_games')) {
            $this->addSql('ALTER TABLE Users DROP COLUMN recent_games');
        }

        if ($this->tableExists('ChallengePlays')) {
            $this->addSql('DROP TABLE ChallengePlays');
        }

        if ($this->tableExists('GameResults') && $this->tableExists('ChallengeSets')) {
            $this->addSql(<<<'SQL'
                UPDATE ChallengeSets cs
                SET play_count = (
                    SELECT COUNT(*)
                    FROM GameResults gr
                    WHERE gr.challenge_set_id = cs.id
                )
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->hasColumn('score')) {
            $this->addSql('ALTER TABLE Users ADD score INT NOT NULL DEFAULT 0');
            $this->addSql('UPDATE Users SET score = leuven_score');
        }

        if ($this->hasColumn('leuven_score')) {
            $this->addSql('ALTER TABLE Users DROP COLUMN leuven_score');
        }

        if ($this->hasColumn('challenge_score')) {
            $this->addSql('ALTER TABLE Users DROP COLUMN challenge_score');
        }

        if ($this->hasColumn('duel_score')) {
            $this->addSql('ALTER TABLE Users DROP COLUMN duel_score');
        }

        if (!$this->hasColumn('recent_games')) {
            $this->addSql("ALTER TABLE Users ADD recent_games JSON NOT NULL DEFAULT (JSON_ARRAY())");
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

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?',
            [$table]
        );
    }
}
