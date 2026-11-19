<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119164510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE meal_plan (id UUID NOT NULL, week_start DATE NOT NULL, leftovers BOOLEAN DEFAULT false NOT NULL, weekday_max_minutes SMALLINT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, household_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX meal_plan_household_week ON meal_plan (household_id, week_start)');
        $this->addSql('CREATE INDEX IDX_C7848889E79FF843 ON meal_plan (household_id)');
        $this->addSql('CREATE TABLE meal_plan_entry (id UUID NOT NULL, servings SMALLINT NOT NULL, locked BOOLEAN DEFAULT false NOT NULL, date DATE NOT NULL, slot VARCHAR(10) NOT NULL, recipe_id UUID DEFAULT NULL, leftover_of_id UUID DEFAULT NULL, plan_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX meal_plan_entry_date ON meal_plan_entry (date)');
        $this->addSql('CREATE UNIQUE INDEX meal_plan_entry_slot ON meal_plan_entry (plan_id, date, slot)');
        $this->addSql('CREATE INDEX IDX_EFEB6E0659D8A214 ON meal_plan_entry (recipe_id)');
        $this->addSql('CREATE INDEX IDX_EFEB6E06CC85822B ON meal_plan_entry (leftover_of_id)');
        $this->addSql('CREATE INDEX IDX_EFEB6E06E899029B ON meal_plan_entry (plan_id)');
        $this->addSql('ALTER TABLE meal_plan ADD CONSTRAINT FK_C7848889E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal_plan_entry ADD CONSTRAINT FK_EFEB6E0659D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal_plan_entry ADD CONSTRAINT FK_EFEB6E06CC85822B FOREIGN KEY (leftover_of_id) REFERENCES meal_plan_entry (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal_plan_entry ADD CONSTRAINT FK_EFEB6E06E899029B FOREIGN KEY (plan_id) REFERENCES meal_plan (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_plan DROP CONSTRAINT FK_C7848889E79FF843');
        $this->addSql('ALTER TABLE meal_plan_entry DROP CONSTRAINT FK_EFEB6E0659D8A214');
        $this->addSql('ALTER TABLE meal_plan_entry DROP CONSTRAINT FK_EFEB6E06CC85822B');
        $this->addSql('ALTER TABLE meal_plan_entry DROP CONSTRAINT FK_EFEB6E06E899029B');
        $this->addSql('DROP TABLE meal_plan');
        $this->addSql('DROP TABLE meal_plan_entry');
    }
}
