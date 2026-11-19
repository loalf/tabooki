<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119113327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1 library: sources (saved links), recipes and their ingredients';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE recipe (id UUID NOT NULL, title VARCHAR(200) NOT NULL, language VARCHAR(2) NOT NULL, servings SMALLINT NOT NULL, prep_minutes SMALLINT DEFAULT NULL, cook_minutes SMALLINT DEFAULT NULL, meal_types JSONB NOT NULL, tags JSONB NOT NULL, steps JSONB NOT NULL, notes TEXT DEFAULT NULL, favorite BOOLEAN DEFAULT false NOT NULL, rating SMALLINT DEFAULT NULL, search_text TEXT DEFAULT \'\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, household_id UUID NOT NULL, source_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX recipe_household_created ON recipe (household_id, created_at)');
        $this->addSql('CREATE INDEX IDX_DA88B137E79FF843 ON recipe (household_id)');
        $this->addSql('CREATE INDEX IDX_DA88B137953C1C61 ON recipe (source_id)');
        $this->addSql('CREATE TABLE recipe_ingredient (id UUID NOT NULL, position SMALLINT NOT NULL, group_name VARCHAR(100) DEFAULT NULL, raw_text VARCHAR(255) NOT NULL, quantity DOUBLE PRECISION DEFAULT NULL, unit VARCHAR(20) DEFAULT NULL, name VARCHAR(150) DEFAULT NULL, prep VARCHAR(150) DEFAULT NULL, optional BOOLEAN DEFAULT false NOT NULL, recipe_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX recipe_ingredient_recipe_position ON recipe_ingredient (recipe_id, position)');
        $this->addSql('CREATE INDEX IDX_22D1FE1359D8A214 ON recipe_ingredient (recipe_id)');
        $this->addSql('CREATE TABLE source (id UUID NOT NULL, normalized_url VARCHAR(2048) NOT NULL, title VARCHAR(200) DEFAULT NULL, author VARCHAR(200) DEFAULT NULL, thumbnail_url VARCHAR(2048) DEFAULT NULL, thumbnail_path VARCHAR(255) DEFAULT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, evidence JSONB DEFAULT \'{}\' NOT NULL, metadata_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, url VARCHAR(2048) NOT NULL, platform VARCHAR(20) NOT NULL, household_id UUID NOT NULL, added_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX source_household_url ON source (household_id, normalized_url)');
        $this->addSql('CREATE INDEX IDX_5F8A7F73E79FF843 ON source (household_id)');
        $this->addSql('CREATE INDEX IDX_5F8A7F7355B127A4 ON source (added_by_id)');
        $this->addSql('ALTER TABLE recipe ADD CONSTRAINT FK_DA88B137E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recipe ADD CONSTRAINT FK_DA88B137953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE1359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE source ADD CONSTRAINT FK_5F8A7F73E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE source ADD CONSTRAINT FK_5F8A7F7355B127A4 FOREIGN KEY (added_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recipe DROP CONSTRAINT FK_DA88B137E79FF843');
        $this->addSql('ALTER TABLE recipe DROP CONSTRAINT FK_DA88B137953C1C61');
        $this->addSql('ALTER TABLE recipe_ingredient DROP CONSTRAINT FK_22D1FE1359D8A214');
        $this->addSql('ALTER TABLE source DROP CONSTRAINT FK_5F8A7F73E79FF843');
        $this->addSql('ALTER TABLE source DROP CONSTRAINT FK_5F8A7F7355B127A4');
        $this->addSql('DROP TABLE recipe');
        $this->addSql('DROP TABLE recipe_ingredient');
        $this->addSql('DROP TABLE source');
    }
}
