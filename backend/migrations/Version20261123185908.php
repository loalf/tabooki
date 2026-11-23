<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123185908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Search by meaning: pgvector, and one embedding per recipe (recipe_embedding)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->addSql('CREATE TABLE recipe_embedding (vector vector(512) NOT NULL, model VARCHAR(100) NOT NULL, digest_hash VARCHAR(32) NOT NULL, embedded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, recipe_id UUID NOT NULL, PRIMARY KEY (recipe_id))');
        $this->addSql('ALTER TABLE recipe_embedding ADD CONSTRAINT FK_5F9A7B4859D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe_embedding DROP CONSTRAINT FK_5F9A7B4859D8A214');
        $this->addSql('DROP TABLE recipe_embedding');
    }
}
