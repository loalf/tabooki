<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Recipes wanted in the next menu (Recipe::$planNext). */
final class Version20261122125020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recipe.plan_next';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD plan_next BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP plan_next');
    }
}
