<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522083924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce one AttributeDefinition per relationEndpoint via partial unique index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_attribute_definition_relation_endpoint ON attribute_definition (relation_endpoint) WHERE relation_endpoint IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_attribute_definition_relation_endpoint');
    }
}
