<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918212000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add centrally managed default and enabled website locales';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql("ALTER TABLE site_settings ADD default_locale VARCHAR(10) DEFAULT 'de' NOT NULL");
        $this->addSql("ALTER TABLE site_settings ADD enabled_locales JSON DEFAULT '[\"de\"]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('ALTER TABLE site_settings DROP default_locale');
        $this->addSql('ALTER TABLE site_settings DROP enabled_locales');
    }
}
