<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123182712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What can stand in for each shared food (food_substitution), and whether it was asked (food.substitutes_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE food_substitution (id UUID NOT NULL, substitute_key VARCHAR(80) NOT NULL, name_es VARCHAR(100) NOT NULL, name_en VARCHAR(100) NOT NULL, ratio DOUBLE PRECISION DEFAULT 1 NOT NULL, note_es VARCHAR(120) DEFAULT NULL, note_en VARCHAR(120) DEFAULT NULL, position SMALLINT NOT NULL, source VARCHAR(10) NOT NULL, food_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX food_substitution_food_substitute ON food_substitution (food_id, substitute_key)');
        $this->addSql('CREATE INDEX IDX_BBB45381BA8E87C4 ON food_substitution (food_id)');
        $this->addSql('ALTER TABLE food_substitution ADD CONSTRAINT FK_BBB45381BA8E87C4 FOREIGN KEY (food_id) REFERENCES food (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE food ADD substitutes_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE food_substitution DROP CONSTRAINT FK_BBB45381BA8E87C4');
        $this->addSql('DROP TABLE food_substitution');
        $this->addSql('ALTER TABLE food DROP substitutes_at');
    }
}
