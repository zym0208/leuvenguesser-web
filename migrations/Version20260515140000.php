<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add friend invitations, migrate UserFriends into Friends, drop UserFriends.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS FriendInvitations (
                id INT AUTO_INCREMENT NOT NULL,
                from_user_id INT NOT NULL,
                to_user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE INDEX friend_invite_unique (from_user_id, to_user_id),
                INDEX friend_invite_to_idx (to_user_id),
                INDEX friend_invite_from_idx (from_user_id),
                CONSTRAINT friend_invite_from_fk FOREIGN KEY (from_user_id) REFERENCES Users (id) ON DELETE CASCADE,
                CONSTRAINT friend_invite_to_fk FOREIGN KEY (to_user_id) REFERENCES Users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);

        if ($this->tableExists('UserFriends')) {
            $this->addSql(<<<'SQL'
                INSERT IGNORE INTO Friends (user1, user2)
                SELECT LEAST(user_id, friend_user_id), GREATEST(user_id, friend_user_id)
                FROM UserFriends
                SQL);
            $this->addSql('DROP TABLE IF EXISTS UserFriends');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS UserFriends (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                friend_user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE INDEX user_friend_unique (user_id, friend_user_id),
                CONSTRAINT user_friends_user_fk FOREIGN KEY (user_id) REFERENCES Users (id) ON DELETE CASCADE,
                CONSTRAINT user_friends_friend_fk FOREIGN KEY (friend_user_id) REFERENCES Users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO UserFriends (user_id, friend_user_id, created_at)
            SELECT user1, user2, NOW() FROM Friends
            SQL);

        $this->addSql('DROP TABLE IF EXISTS FriendInvitations');
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
