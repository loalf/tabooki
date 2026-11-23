<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123173449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Whether every line a recipe needs has a described food (app:foods fills it in)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD allergens_complete BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP allergens_complete');
    }
}
