<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** M6: LLM calls per household (classification too), meal ratings, when a recipe was extracted. */
final class Version20261121083008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M6: extraction_run.household_id (source optional), meal_plan_entry.rating, recipe.extracted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extraction_run DROP CONSTRAINT fk_36363425953c1c61');
        $this->addSql('ALTER TABLE extraction_run ADD household_id UUID DEFAULT NULL');
        $this->addSql('UPDATE extraction_run r SET household_id = s.household_id FROM source s WHERE s.id = r.source_id');
        $this->addSql('ALTER TABLE extraction_run ALTER household_id SET NOT NULL');
        $this->addSql('ALTER TABLE extraction_run ALTER source_id DROP NOT NULL');
        $this->addSql('ALTER TABLE extraction_run ADD CONSTRAINT FK_36363425953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE extraction_run ADD CONSTRAINT FK_36363425E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX extraction_run_household_created ON extraction_run (household_id, created_at)');
        $this->addSql('CREATE INDEX IDX_36363425E79FF843 ON extraction_run (household_id)');
        $this->addSql('ALTER TABLE meal_plan_entry ADD rating SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD extracted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE recipe SET extracted_at = created_at WHERE extracted_by IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extraction_run DROP CONSTRAINT FK_36363425953C1C61');
        $this->addSql('ALTER TABLE extraction_run DROP CONSTRAINT FK_36363425E79FF843');
        $this->addSql('DROP INDEX extraction_run_household_created');
        $this->addSql('DROP INDEX IDX_36363425E79FF843');
        $this->addSql('ALTER TABLE extraction_run DROP household_id');
        $this->addSql('DELETE FROM extraction_run WHERE source_id IS NULL');
        $this->addSql('ALTER TABLE extraction_run ALTER source_id SET NOT NULL');
        $this->addSql('ALTER TABLE extraction_run ADD CONSTRAINT fk_36363425953c1c61 FOREIGN KEY (source_id) REFERENCES source (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE meal_plan_entry DROP rating');
        $this->addSql('ALTER TABLE recipe DROP extracted_at');
    }
}
