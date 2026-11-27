<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add normalized game result tables for daily and classic challenge scoring.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS GameResults (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                mode VARCHAR(20) NOT NULL,
                challenge_set_id INT NULL,
                user_id INT NULL,
                visitor_key VARCHAR(32) NOT NULL,
                total_score INT NOT NULL DEFAULT 0,
                max_score INT NOT NULL DEFAULT 0,
                round_count INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                INDEX game_result_mode_score_idx (mode, total_score),
                INDEX game_result_challenge_score_idx (challenge_set_id, total_score),
                INDEX game_result_user_time_idx (user_id, created_at),
                INDEX game_result_visitor_time_idx (visitor_key, created_at),
                CONSTRAINT game_result_challenge_fk FOREIGN KEY (challenge_set_id) REFERENCES ChallengeSets (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS GameResultRounds (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                game_result_id INT UNSIGNED NOT NULL,
                location_id INT NULL,
                round_number INT NOT NULL,
                guess_lat DOUBLE NULL,
                guess_lng DOUBLE NULL,
                actual_lat DOUBLE NULL,
                actual_lng DOUBLE NULL,
                distance_meters DOUBLE NULL,
                score INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE INDEX game_result_round_unique (game_result_id, round_number),
                INDEX game_result_round_location_idx (location_id),
                CONSTRAINT game_result_round_result_fk FOREIGN KEY (game_result_id) REFERENCES GameResults (id) ON DELETE CASCADE,
                CONSTRAINT game_result_round_location_fk FOREIGN KEY (location_id) REFERENCES Locations (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE GameResults CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE GameResultRounds CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        if ($this->tableExists('DailyChallengeResults')) {
            $this->addSql(<<<'SQL'
                INSERT INTO GameResults (mode, challenge_set_id, user_id, visitor_key, total_score, max_score, round_count, created_at)
                SELECT 'daily',
                       NULL,
                       user_id,
                       visitor_key,
                       total_score,
                       5000,
                       CASE WHEN JSON_VALID(round_scores) THEN JSON_LENGTH(round_scores) ELSE 5 END,
                       played_at
                FROM DailyChallengeResults dcr
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM GameResults gr
                    WHERE gr.mode = 'daily'
                      AND gr.challenge_set_id IS NULL
                      AND gr.user_id <=> dcr.user_id
                      AND gr.visitor_key COLLATE utf8mb4_unicode_ci = dcr.visitor_key COLLATE utf8mb4_unicode_ci
                      AND gr.total_score = dcr.total_score
                      AND gr.created_at = dcr.played_at
                )
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS GameResultRounds');
        $this->addSql('DROP TABLE IF EXISTS GameResults');
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
