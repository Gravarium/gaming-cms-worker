<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918202000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist central CMS module activation state'; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('CREATE TABLE cms_module_state (module_key VARCHAR(64) NOT NULL, enabled BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(module_key))');
        $this->addSql("COMMENT ON COLUMN cms_module_state.updated_at IS '(DC2Type:datetime_immutable)'");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE cms_module_state'); }
}
