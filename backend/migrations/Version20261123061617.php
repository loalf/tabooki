<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123061617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'food: the shared food catalog (nutrition, allergens, weights); ingredient.food_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE food (id UUID NOT NULL, key VARCHAR(80) NOT NULL, name_es VARCHAR(100) DEFAULT NULL, name_en VARCHAR(100) DEFAULT NULL, kcal DOUBLE PRECISION DEFAULT NULL, protein DOUBLE PRECISION DEFAULT NULL, fat DOUBLE PRECISION DEFAULT NULL, saturated_fat DOUBLE PRECISION DEFAULT NULL, carbs DOUBLE PRECISION DEFAULT NULL, sugar DOUBLE PRECISION DEFAULT NULL, fiber DOUBLE PRECISION DEFAULT NULL, salt DOUBLE PRECISION DEFAULT NULL, food_groups JSONB DEFAULT \'[]\' NOT NULL, allergens JSONB DEFAULT \'[]\' NOT NULL, spice SMALLINT DEFAULT NULL, grams_per_piece DOUBLE PRECISION DEFAULT NULL, density DOUBLE PRECISION DEFAULT NULL, source VARCHAR(10) DEFAULT NULL, described_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, edited BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D43829F78A90ABA9 ON food (key)');
        $this->addSql('ALTER TABLE ingredient ADD food_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ingredient ADD CONSTRAINT FK_6BAF7870BA8E87C4 FOREIGN KEY (food_id) REFERENCES food (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6BAF7870BA8E87C4 ON ingredient (food_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingredient DROP CONSTRAINT FK_6BAF7870BA8E87C4');
        $this->addSql('DROP INDEX IDX_6BAF7870BA8E87C4');
        $this->addSql('ALTER TABLE ingredient DROP food_id');
        $this->addSql('DROP TABLE food');
    }
}
