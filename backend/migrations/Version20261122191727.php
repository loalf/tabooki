<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122191727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'swap_feedback: why meals were swapped; taste_profile.dismissed_suggestions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE swap_feedback (id UUID NOT NULL, recipe_title VARCHAR(200) NOT NULL, reason VARCHAR(20) NOT NULL, what JSONB DEFAULT NULL, slot VARCHAR(10) NOT NULL, weekday BOOLEAN NOT NULL, minutes SMALLINT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, household_id UUID NOT NULL, recipe_id UUID DEFAULT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX swap_feedback_household_created ON swap_feedback (household_id, created_at)');
        $this->addSql('CREATE INDEX IDX_256BE745E79FF843 ON swap_feedback (household_id)');
        $this->addSql('CREATE INDEX IDX_256BE74559D8A214 ON swap_feedback (recipe_id)');
        $this->addSql('CREATE INDEX IDX_256BE745B03A8386 ON swap_feedback (created_by_id)');
        $this->addSql('ALTER TABLE swap_feedback ADD CONSTRAINT FK_256BE745E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE swap_feedback ADD CONSTRAINT FK_256BE74559D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE swap_feedback ADD CONSTRAINT FK_256BE745B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE taste_profile ADD dismissed_suggestions JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_feedback DROP CONSTRAINT FK_256BE745E79FF843');
        $this->addSql('ALTER TABLE swap_feedback DROP CONSTRAINT FK_256BE74559D8A214');
        $this->addSql('ALTER TABLE swap_feedback DROP CONSTRAINT FK_256BE745B03A8386');
        $this->addSql('DROP TABLE swap_feedback');
        $this->addSql('ALTER TABLE taste_profile DROP dismissed_suggestions');
    }
}
