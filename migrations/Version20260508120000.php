<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create DailyChallengeResults table for 5-round daily challenge scoring';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `DailyChallengeResults` (
                `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id`      INT NULL,
                `visitor_key`  VARCHAR(32) NOT NULL,
                `total_score`  INT NOT NULL DEFAULT 0,
                `round_scores` JSON NOT NULL,
                `played_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_dcr_score` (`total_score`),
                INDEX `idx_dcr_played` (`played_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `DailyChallengeResults`');
    }
}
