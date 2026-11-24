<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261124044048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Who chose a food\'s substitutes by hand, and when';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE food ADD substitutes_chosen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE food ADD substitutes_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE food ADD CONSTRAINT FK_D43829F7CFBCDC04 FOREIGN KEY (substitutes_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D43829F7CFBCDC04 ON food (substitutes_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE food DROP CONSTRAINT FK_D43829F7CFBCDC04');
        $this->addSql('DROP INDEX IDX_D43829F7CFBCDC04');
        $this->addSql('ALTER TABLE food DROP substitutes_chosen_at');
        $this->addSql('ALTER TABLE food DROP substitutes_by_id');
    }
}
