<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3 — Plan 01.
 *
 *   - asset_transformation.warnings (JSONB NOT NULL DEFAULT '[]')
 *     Persisted warnings recomputed at every flush by TransformationHashListener
 *     (e.g. alpha-flatten-on-jpeg). Surfaced to the PWA editor (Phase 7) and to
 *     the runtime header X-Transformation-Warnings (Plan 03).
 *
 *   - asset.is_public (BOOLEAN NOT NULL DEFAULT false)
 *     Hard prerequisite for ROUTE-08 — the public transformation route MUST
 *     check this flag; default false is the safest state (404 until opt-in).
 */
final class Version20260527000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add asset_transformation.warnings JSONB and asset.is_public BOOLEAN columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE asset_transformation ADD COLUMN warnings JSONB NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE asset ADD COLUMN is_public BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset_transformation DROP COLUMN warnings');
        $this->addSql('ALTER TABLE asset DROP COLUMN is_public');
    }
}
