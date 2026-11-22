<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122170259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'taste_profile: what each household likes; meal_plan_entry.reasons: why each meal was chosen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE taste_profile (id UUID NOT NULL, answers JSONB NOT NULL, rules JSONB NOT NULL, notes TEXT DEFAULT NULL, summary JSONB DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, household_id UUID NOT NULL, updated_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D8653117E79FF843 ON taste_profile (household_id)');
        $this->addSql('CREATE INDEX IDX_D8653117896DBBDE ON taste_profile (updated_by_id)');
        $this->addSql('ALTER TABLE taste_profile ADD CONSTRAINT FK_D8653117E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE taste_profile ADD CONSTRAINT FK_D8653117896DBBDE FOREIGN KEY (updated_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal_plan_entry ADD reasons JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE taste_profile DROP CONSTRAINT FK_D8653117E79FF843');
        $this->addSql('ALTER TABLE taste_profile DROP CONSTRAINT FK_D8653117896DBBDE');
        $this->addSql('DROP TABLE taste_profile');
        $this->addSql('ALTER TABLE meal_plan_entry DROP reasons');
    }
}
