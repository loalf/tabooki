<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122164437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recipe facts: heaviness, spice and food groups, for the taste profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD heaviness VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD spice SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD food_groups JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE recipe ADD optional_food_groups JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE recipe ADD facts_from VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD facts_edited JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP heaviness');
        $this->addSql('ALTER TABLE recipe DROP spice');
        $this->addSql('ALTER TABLE recipe DROP food_groups');
        $this->addSql('ALTER TABLE recipe DROP optional_food_groups');
        $this->addSql('ALTER TABLE recipe DROP facts_from');
        $this->addSql('ALTER TABLE recipe DROP facts_edited');
    }
}
