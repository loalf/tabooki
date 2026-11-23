<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123065338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recipe_ingredient.grams and grams_source: what each line weighs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe_ingredient ADD grams DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe_ingredient ADD grams_source VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe_ingredient DROP grams');
        $this->addSql('ALTER TABLE recipe_ingredient DROP grams_source');
    }
}
