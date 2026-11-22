<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122143901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recipe.translation: the recipe in the other language';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD translation JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP translation');
    }
}
