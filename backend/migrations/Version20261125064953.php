<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125064953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Whether a planned meal was cooked; meals already rated were';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_plan_entry ADD cooked BOOLEAN DEFAULT NULL');
        $this->addSql('UPDATE meal_plan_entry SET cooked = true WHERE rating IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_plan_entry DROP cooked');
    }
}
