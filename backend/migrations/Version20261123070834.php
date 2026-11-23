<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123070834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recipe.nutrition, allergens, optional_allergens: added up from the lines and their foods';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD nutrition JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD allergens JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE recipe ADD optional_allergens JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP nutrition');
        $this->addSql('ALTER TABLE recipe DROP allergens');
        $this->addSql('ALTER TABLE recipe DROP optional_allergens');
    }
}
