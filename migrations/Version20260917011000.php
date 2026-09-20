<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917011000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add TOTP two-factor authentication to users'; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('ALTER TABLE cms_user ADD two_factor_enabled BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE cms_user ADD two_factor_secret TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE cms_user ADD two_factor_recovery_codes JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cms_user DROP two_factor_enabled');
        $this->addSql('ALTER TABLE cms_user DROP two_factor_secret');
        $this->addSql('ALTER TABLE cms_user DROP two_factor_recovery_codes');
    }
}
