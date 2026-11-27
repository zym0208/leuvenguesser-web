<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill daily round score rows from DailyChallengeResults JSON arrays.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('DailyChallengeResults')) {
            return;
        }

        $this->addSql(<<<'SQL'
            INSERT INTO GameResultRounds (game_result_id, location_id, round_number, score)
            SELECT gr.id,
                   NULL,
                   jt.round_number,
                   GREATEST(0, LEAST(1000, CAST(jt_source.score AS SIGNED)))
            FROM DailyChallengeResults dcr
            JOIN GameResults gr
              ON gr.mode = 'daily'
             AND gr.challenge_set_id IS NULL
             AND gr.user_id <=> dcr.user_id
             AND gr.visitor_key COLLATE utf8mb4_unicode_ci = dcr.visitor_key COLLATE utf8mb4_unicode_ci
             AND gr.total_score = dcr.total_score
             AND gr.created_at = dcr.played_at
            JOIN JSON_TABLE(
                dcr.round_scores,
                '$[*]' COLUMNS (
                    ord FOR ORDINALITY,
                    score INT PATH '$'
                )
            ) jt_source
            JOIN (
                SELECT 1 AS ord, 1 AS round_number UNION ALL
                SELECT 2, 2 UNION ALL
                SELECT 3, 3 UNION ALL
                SELECT 4, 4 UNION ALL
                SELECT 5, 5
            ) jt ON jt.ord = jt_source.ord
            WHERE JSON_VALID(dcr.round_scores)
              AND NOT EXISTS (
                  SELECT 1
                  FROM GameResultRounds existing
                  WHERE existing.game_result_id = gr.id
                    AND existing.round_number = jt.round_number
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE grr
            FROM GameResultRounds grr
            JOIN GameResults gr ON gr.id = grr.game_result_id
            WHERE gr.mode = 'daily'
              AND gr.challenge_set_id IS NULL
              AND grr.location_id IS NULL
              AND grr.guess_lat IS NULL
              AND grr.guess_lng IS NULL
              AND grr.actual_lat IS NULL
              AND grr.actual_lng IS NULL
              AND grr.distance_meters IS NULL
            SQL);
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
