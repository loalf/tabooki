<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123193148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Where the figures of a food come from in its source (BEDCA), and which are still estimated';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE food ADD source_ref VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE food ADD estimated_fields JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE food DROP source_ref');
        $this->addSql('ALTER TABLE food DROP estimated_fields');
    }
}
