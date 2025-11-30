<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251125132221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE author ALTER is_alive DROP DEFAULT');
        $this->addSql('ALTER TABLE author ALTER is_alive TYPE BOOLEAN USING is_alive::boolean');
        $this->addSql('ALTER TABLE author ALTER is_alive SET DEFAULT true');
        $this->addSql('ALTER TABLE author ALTER is_alive SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE author ALTER is_alive TYPE INT');
        $this->addSql('ALTER TABLE author ALTER is_alive SET DEFAULT 1');
    }
}
