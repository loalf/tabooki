<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123191940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Who fixed a food by hand, and when';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE food ADD corrected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE food ADD corrected_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE food ADD CONSTRAINT FK_D43829F78CF37DC4 FOREIGN KEY (corrected_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D43829F78CF37DC4 ON food (corrected_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE food DROP CONSTRAINT FK_D43829F78CF37DC4');
        $this->addSql('DROP INDEX IDX_D43829F78CF37DC4');
        $this->addSql('ALTER TABLE food DROP corrected_at');
        $this->addSql('ALTER TABLE food DROP corrected_by_id');
    }
}
