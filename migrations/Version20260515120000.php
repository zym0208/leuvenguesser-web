<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UserFriends table for friend relationships between users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS UserFriends (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                friend_user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE INDEX user_friend_unique (user_id, friend_user_id),
                INDEX user_friends_user_idx (user_id),
                INDEX user_friends_friend_idx (friend_user_id),
                CONSTRAINT user_friends_user_fk FOREIGN KEY (user_id) REFERENCES Users (id) ON DELETE CASCADE,
                CONSTRAINT user_friends_friend_fk FOREIGN KEY (friend_user_id) REFERENCES Users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS UserFriends');
    }
}
