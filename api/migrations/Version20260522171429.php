<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522171429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable pgvector extension, add embedding columns to asset, create asset_similarity table with HNSW index';
    }

    public function up(Schema $schema): void
    {
        // pgvector extension — provided by the pgvector/pgvector:pg16 Docker image.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        // Similarity link table (undirected — CHECK enforces asset_a_id < asset_b_id).
        $this->addSql('CREATE TABLE asset_similarity (score DOUBLE PRECISION NOT NULL, asset_a_id INT NOT NULL, asset_b_id INT NOT NULL, PRIMARY KEY (asset_a_id, asset_b_id), CONSTRAINT asset_similarity_order CHECK (asset_a_id < asset_b_id))');
        $this->addSql('CREATE INDEX IDX_A2CB421ADFBA9690 ON asset_similarity (asset_a_id)');
        $this->addSql('CREATE INDEX IDX_A2CB421ACD0F397E ON asset_similarity (asset_b_id)');
        $this->addSql('ALTER TABLE asset_similarity ADD CONSTRAINT FK_A2CB421ADFBA9690 FOREIGN KEY (asset_a_id) REFERENCES asset (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE asset_similarity ADD CONSTRAINT FK_A2CB421ACD0F397E FOREIGN KEY (asset_b_id) REFERENCES asset (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Embedding columns on asset.
        $this->addSql('ALTER TABLE asset ADD embedding vector(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD embedding_model VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD embedding_status VARCHAR(20) DEFAULT \'pending\' NOT NULL');
        $this->addSql('ALTER TABLE asset ADD duplicate_of_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD CONSTRAINT FK_2AF5A5C2CC33300 FOREIGN KEY (duplicate_of_id) REFERENCES asset (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2AF5A5C2CC33300 ON asset (duplicate_of_id)');
        $this->addSql('CREATE INDEX idx_asset_embedding_status ON asset (embedding_status)');

        // HNSW index for fast ANN search using cosine distance (`<=>` operator).
        // m=16 / ef_construction=64 are the upstream defaults — fine up to millions of rows.
        $this->addSql('CREATE INDEX idx_asset_embedding_hnsw ON asset USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_asset_embedding_hnsw');
        $this->addSql('DROP INDEX IF EXISTS idx_asset_embedding_status');
        $this->addSql('ALTER TABLE asset_similarity DROP CONSTRAINT FK_A2CB421ADFBA9690');
        $this->addSql('ALTER TABLE asset_similarity DROP CONSTRAINT FK_A2CB421ACD0F397E');
        $this->addSql('DROP TABLE asset_similarity');
        $this->addSql('ALTER TABLE asset DROP CONSTRAINT FK_2AF5A5C2CC33300');
        $this->addSql('DROP INDEX IF EXISTS IDX_2AF5A5C2CC33300');
        $this->addSql('ALTER TABLE asset DROP embedding');
        $this->addSql('ALTER TABLE asset DROP embedding_model');
        $this->addSql('ALTER TABLE asset DROP embedding_status');
        $this->addSql('ALTER TABLE asset DROP duplicate_of_id');
        // We intentionally do not drop the vector extension — other apps may use it.
    }
}
