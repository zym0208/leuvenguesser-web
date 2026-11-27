<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502201000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add regions, challenge sets, likes, comments, and play analytics.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS Regions (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(90) NOT NULL,
                slug VARCHAR(90) NOT NULL,
                center_lat DOUBLE NOT NULL,
                center_lng DOUBLE NOT NULL,
                sw_lat DOUBLE NOT NULL,
                sw_lng DOUBLE NOT NULL,
                ne_lat DOUBLE NOT NULL,
                ne_lng DOUBLE NOT NULL,
                PRIMARY KEY(id),
                UNIQUE INDEX region_slug_unique (slug)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ChallengeSets (
                id INT AUTO_INCREMENT NOT NULL,
                title VARCHAR(120) NOT NULL,
                description TEXT NULL,
                region_id INT NOT NULL,
                author_user_id INT NULL,
                author_name VARCHAR(90) NOT NULL,
                creator_key VARCHAR(64) NOT NULL,
                play_count INT NOT NULL DEFAULT 0,
                like_count INT NOT NULL DEFAULT 0,
                comment_count INT NOT NULL DEFAULT 0,
                published TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                INDEX challenge_region_idx (region_id),
                INDEX challenge_creator_idx (creator_key),
                INDEX challenge_author_idx (author_name),
                CONSTRAINT challenge_region_fk FOREIGN KEY (region_id) REFERENCES Regions (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ChallengeSetLocations (
                challenge_set_id INT NOT NULL,
                location_id INT NOT NULL,
                position INT NOT NULL DEFAULT 0,
                PRIMARY KEY(challenge_set_id, location_id),
                INDEX challenge_location_idx (location_id),
                CONSTRAINT challenge_set_location_set_fk FOREIGN KEY (challenge_set_id) REFERENCES ChallengeSets (id) ON DELETE CASCADE,
                CONSTRAINT challenge_set_location_location_fk FOREIGN KEY (location_id) REFERENCES Locations (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ChallengeLikes (
                id INT AUTO_INCREMENT NOT NULL,
                challenge_set_id INT NOT NULL,
                visitor_key VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE INDEX challenge_like_unique (challenge_set_id, visitor_key),
                INDEX challenge_like_set_idx (challenge_set_id),
                CONSTRAINT challenge_like_set_fk FOREIGN KEY (challenge_set_id) REFERENCES ChallengeSets (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ChallengeComments (
                id INT AUTO_INCREMENT NOT NULL,
                challenge_set_id INT NOT NULL,
                visitor_key VARCHAR(64) NOT NULL,
                author_name VARCHAR(90) NOT NULL,
                body VARCHAR(800) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                INDEX challenge_comment_set_idx (challenge_set_id),
                CONSTRAINT challenge_comment_set_fk FOREIGN KEY (challenge_set_id) REFERENCES ChallengeSets (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ChallengePlays (
                id INT AUTO_INCREMENT NOT NULL,
                challenge_set_id INT NOT NULL,
                location_id INT NULL,
                visitor_key VARCHAR(64) NOT NULL,
                played_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                INDEX challenge_play_set_time_idx (challenge_set_id, played_at),
                INDEX challenge_play_visitor_idx (visitor_key),
                CONSTRAINT challenge_play_set_fk FOREIGN KEY (challenge_set_id) REFERENCES ChallengeSets (id) ON DELETE CASCADE,
                CONSTRAINT challenge_play_location_fk FOREIGN KEY (location_id) REFERENCES Locations (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);

        $this->seedRegions();
        $this->seedLeuvenStarterSet();
        $this->seedDemoAnalytics();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ChallengePlays');
        $this->addSql('DROP TABLE IF EXISTS ChallengeComments');
        $this->addSql('DROP TABLE IF EXISTS ChallengeLikes');
        $this->addSql('DROP TABLE IF EXISTS ChallengeSetLocations');
        $this->addSql('DROP TABLE IF EXISTS ChallengeSets');
        $this->addSql('DROP TABLE IF EXISTS Regions');
    }

    private function seedRegions(): void
    {
        $regions = [
            ['Leuven', 'leuven', 50.8798, 4.7005, 50.85, 4.66, 50.90, 4.74],
            ['Brussels', 'brussels', 50.8467, 4.3525, 50.78, 4.25, 50.91, 4.48],
            ['Belgium', 'belgium', 50.6403, 4.6667, 49.45, 2.50, 51.55, 6.40],
            ['Europe', 'europe', 48.8566, 2.3522, 34.50, -10.50, 71.20, 40.30],
            ['World', 'world', 20.0, 0.0, -85.0, -180.0, 85.0, 180.0],
        ];

        foreach ($regions as $region) {
            $this->addSql(
                'INSERT IGNORE INTO Regions (name, slug, center_lat, center_lng, sw_lat, sw_lng, ne_lat, ne_lng)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                $region
            );
        }
    }

    private function seedLeuvenStarterSet(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO ChallengeSets (title, description, region_id, author_name, creator_key, play_count, like_count, comment_count)
            SELECT 'Leuven Starter', 'A compact starter set using the original Leuven locations.', r.id, 'Team 408', 'seed-team-408', 18, 5, 1
            FROM Regions r
            WHERE r.slug = 'leuven'
              AND NOT EXISTS (SELECT 1 FROM ChallengeSets WHERE creator_key = 'seed-team-408' AND title = 'Leuven Starter')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO ChallengeSetLocations (challenge_set_id, location_id, position)
            SELECT cs.id, l.id, ROW_NUMBER() OVER (ORDER BY l.id)
            FROM ChallengeSets cs
            JOIN Locations l
            WHERE cs.creator_key = 'seed-team-408'
              AND cs.title = 'Leuven Starter'
              AND l.photo_source IN ('ladeuze.jpg', 'cemetery.jpg', 'begijnhof.jpg', 'leo.jpg')
            SQL);
    }

    private function seedDemoAnalytics(): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO ChallengeLikes (challenge_set_id, visitor_key, created_at)
            SELECT cs.id, CONCAT('seed-like-', n.n), DATE_SUB(NOW(), INTERVAL n.n HOUR)
            FROM ChallengeSets cs
            JOIN (
                SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
            ) n
            WHERE cs.creator_key = 'seed-team-408' AND cs.title = 'Leuven Starter'
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO ChallengeComments (challenge_set_id, visitor_key, author_name, body, created_at)
            SELECT cs.id, 'seed-comment-1', 'Demo Player', 'Good compact route around Leuven.', DATE_SUB(NOW(), INTERVAL 3 HOUR)
            FROM ChallengeSets cs
            WHERE cs.creator_key = 'seed-team-408'
              AND cs.title = 'Leuven Starter'
              AND NOT EXISTS (SELECT 1 FROM ChallengeComments WHERE visitor_key = 'seed-comment-1')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO ChallengePlays (challenge_set_id, location_id, visitor_key, played_at)
            SELECT cs.id, csl.location_id, CONCAT('seed-play-', h.h), DATE_SUB(NOW(), INTERVAL h.h HOUR)
            FROM ChallengeSets cs
            JOIN ChallengeSetLocations csl ON csl.challenge_set_id = cs.id
            JOIN (
                SELECT 1 AS h UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 6 UNION SELECT 8
                UNION SELECT 12 UNION SELECT 18 UNION SELECT 26 UNION SELECT 31 UNION SELECT 48 UNION SELECT 72
            ) h
            WHERE cs.creator_key = 'seed-team-408'
              AND cs.title = 'Leuven Starter'
              AND csl.position = 1
            SQL);
    }
}
