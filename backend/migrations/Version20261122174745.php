<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122174745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recipe.notes_judgement: how each recipe fits the household\'s free-text notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD notes_judgement JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP notes_judgement');
    }
}
