<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526100116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop asset.code column (no longer used)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_2af5a5c77153098');
        $this->addSql('ALTER TABLE asset DROP COLUMN code');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset ADD code VARCHAR(50) NOT NULL DEFAULT \'\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_2af5a5c77153098 ON asset (code)');
    }
}
