<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119170222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ingredient (id UUID NOT NULL, canonical_key VARCHAR(80) NOT NULL, name_es VARCHAR(100) DEFAULT NULL, name_en VARCHAR(100) DEFAULT NULL, aisle VARCHAR(20) DEFAULT \'other\' NOT NULL, staple BOOLEAN DEFAULT false NOT NULL, classified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, edited BOOLEAN DEFAULT false NOT NULL, household_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX ingredient_household_key ON ingredient (household_id, canonical_key)');
        $this->addSql('CREATE INDEX IDX_6BAF7870E79FF843 ON ingredient (household_id)');
        $this->addSql('CREATE TABLE shopping_list (id UUID NOT NULL, week_start DATE NOT NULL, checked JSONB NOT NULL, household_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX shopping_list_household_week ON shopping_list (household_id, week_start)');
        $this->addSql('CREATE INDEX IDX_3DC1A459E79FF843 ON shopping_list (household_id)');
        $this->addSql('CREATE TABLE shopping_list_item (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, text VARCHAR(200) NOT NULL, list_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4FB1C2243DAE168B ON shopping_list_item (list_id)');
        $this->addSql('ALTER TABLE ingredient ADD CONSTRAINT FK_6BAF7870E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shopping_list ADD CONSTRAINT FK_3DC1A459E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shopping_list_item ADD CONSTRAINT FK_4FB1C2243DAE168B FOREIGN KEY (list_id) REFERENCES shopping_list (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ingredient DROP CONSTRAINT FK_6BAF7870E79FF843');
        $this->addSql('ALTER TABLE shopping_list DROP CONSTRAINT FK_3DC1A459E79FF843');
        $this->addSql('ALTER TABLE shopping_list_item DROP CONSTRAINT FK_4FB1C2243DAE168B');
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('DROP TABLE shopping_list');
        $this->addSql('DROP TABLE shopping_list_item');
    }
}
