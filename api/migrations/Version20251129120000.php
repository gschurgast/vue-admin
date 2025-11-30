<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251129120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create chat_message table for AI assistant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_message (id SERIAL PRIMARY KEY, message TEXT NOT NULL, response TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('COMMENT ON COLUMN chat_message.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_message');
    }
}
