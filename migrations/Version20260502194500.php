<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store uploaded challenge images directly in the Locations table.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('photo_data')) {
            $this->addSql('ALTER TABLE Locations ADD photo_data LONGBLOB NULL AFTER photo_source');
        }

        if (!$this->hasColumn('photo_mime_type')) {
            $this->addSql('ALTER TABLE Locations ADD photo_mime_type VARCHAR(80) NULL AFTER photo_data');
        }

        if (!$this->hasColumn('photo_size')) {
            $this->addSql('ALTER TABLE Locations ADD photo_size INT NULL AFTER photo_mime_type');
        }

        if (!$this->hasColumn('uploaded_at')) {
            $this->addSql('ALTER TABLE Locations ADD uploaded_at DATETIME NULL AFTER photo_size');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->hasColumn('uploaded_at')) {
            $this->addSql('ALTER TABLE Locations DROP COLUMN uploaded_at');
        }

        if ($this->hasColumn('photo_size')) {
            $this->addSql('ALTER TABLE Locations DROP COLUMN photo_size');
        }

        if ($this->hasColumn('photo_mime_type')) {
            $this->addSql('ALTER TABLE Locations DROP COLUMN photo_mime_type');
        }

        if ($this->hasColumn('photo_data')) {
            $this->addSql('ALTER TABLE Locations DROP COLUMN photo_data');
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
            ['Locations', $column]
        );
    }
}
