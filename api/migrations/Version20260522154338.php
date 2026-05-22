<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enforce unique (attribute_definition_id, code) on attribute_option so two
 * options can no longer share the same code under the same attribute.
 */
final class Version20260522154338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UNIQUE constraint (attribute_definition_id, code) on attribute_option';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_attribute_code ON attribute_option (attribute_definition_id, code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_attribute_code');
    }
}