<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track installed CMS module packages and versions';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql("ALTER TABLE cms_module_state ADD installed BOOLEAN DEFAULT TRUE NOT NULL");
        $this->addSql("ALTER TABLE cms_module_state ADD installed_version VARCHAR(32) DEFAULT '1.0.0'");
        $this->addSql('ALTER TABLE cms_module_state ADD installed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN cms_module_state.installed_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cms_module_state DROP installed');
        $this->addSql('ALTER TABLE cms_module_state DROP installed_version');
        $this->addSql('ALTER TABLE cms_module_state DROP installed_at');
    }
}
