<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122191103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'meal_plan.tweak*: a wish for one week only, as rules on top of the household\'s';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_plan ADD tweak TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_plan ADD tweak_rules JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_plan ADD tweak_label JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_plan ADD tweak_avoids JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_plan DROP tweak');
        $this->addSql('ALTER TABLE meal_plan DROP tweak_rules');
        $this->addSql('ALTER TABLE meal_plan DROP tweak_label');
        $this->addSql('ALTER TABLE meal_plan DROP tweak_avoids');
    }
}
