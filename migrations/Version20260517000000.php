<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename game modes: daily -> leuven_challenge, classic -> world_challenge.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE GameResults SET mode = 'leuven_challenge' WHERE mode = 'daily'");
        $this->addSql("UPDATE GameResults SET mode = 'world_challenge' WHERE mode = 'classic'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE GameResults SET mode = 'daily' WHERE mode = 'leuven_challenge'");
        $this->addSql("UPDATE GameResults SET mode = 'classic' WHERE mode = 'world_challenge'");
    }
}
