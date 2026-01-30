<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251202133217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attribute_option_translation DROP CONSTRAINT fk_e4dcc60da7c41d6f');
        $this->addSql('ALTER TABLE attribute_option_translation ADD CONSTRAINT FK_E4DCC60DA7C41D6F FOREIGN KEY (option_id) REFERENCES attribute_option (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attribute_option_translation DROP CONSTRAINT FK_E4DCC60DA7C41D6F');
        $this->addSql('ALTER TABLE attribute_option_translation ADD CONSTRAINT fk_e4dcc60da7c41d6f FOREIGN KEY (option_id) REFERENCES attribute_option (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
