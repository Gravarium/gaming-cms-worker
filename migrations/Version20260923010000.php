<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist per-page portal layouts with optimistic concurrency protection'; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('CREATE TABLE page_layout (context VARCHAR(80) NOT NULL, version INT DEFAULT 1 NOT NULL, document JSON NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(context))');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Saved page layouts must be exported before removal.'); }
}
