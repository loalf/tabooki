<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261127083957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sign in with Google: the linked Google account of each user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD google_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D64976F5C865 ON "user" (google_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D64976F5C865');
        $this->addSql('ALTER TABLE "user" DROP google_id');
    }
}
