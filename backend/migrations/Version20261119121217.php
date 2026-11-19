<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119121217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M2 extraction: run log, review flags, status messages, canonical ingredient keys';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE extraction_run (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, step VARCHAR(20) NOT NULL, provider VARCHAR(30) NOT NULL, model VARCHAR(120) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, cost_usd DOUBLE PRECISION DEFAULT NULL, ok BOOLEAN NOT NULL, error VARCHAR(1000) DEFAULT NULL, duration_ms INT NOT NULL, source_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX extraction_run_created ON extraction_run (created_at)');
        $this->addSql('CREATE INDEX IDX_36363425953C1C61 ON extraction_run (source_id)');
        $this->addSql('ALTER TABLE extraction_run ADD CONSTRAINT FK_36363425953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recipe ADD needs_review BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE recipe ADD confidence DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD extracted_by VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe_ingredient ADD canonical_key VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD status_message VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE extraction_run DROP CONSTRAINT FK_36363425953C1C61');
        $this->addSql('DROP TABLE extraction_run');
        $this->addSql('ALTER TABLE recipe DROP needs_review');
        $this->addSql('ALTER TABLE recipe DROP confidence');
        $this->addSql('ALTER TABLE recipe DROP extracted_by');
        $this->addSql('ALTER TABLE recipe_ingredient DROP canonical_key');
        $this->addSql('ALTER TABLE source DROP status_message');
    }
}
