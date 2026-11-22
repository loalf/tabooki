<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Password reset links (App\Entity\PasswordResetToken). */
final class Version20261121101914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'password_reset_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_reset_token (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, selector VARCHAR(24) NOT NULL, verifier_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B7BA4B69692E25D ON password_reset_token (selector)');
        $this->addSql('CREATE INDEX password_reset_token_expires ON password_reset_token (expires_at)');
        $this->addSql('CREATE INDEX IDX_6B7BA4B6A76ED395 ON password_reset_token (user_id)');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE password_reset_token DROP CONSTRAINT FK_6B7BA4B6A76ED395');
        $this->addSql('DROP TABLE password_reset_token');
    }
}
