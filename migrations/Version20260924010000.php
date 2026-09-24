<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store structured content editor documents separately from the readable content body';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('ALTER TABLE content_entry ADD editor_document TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE content_revision ADD editor_document TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Structured editor documents must be exported before removing their storage columns.');
    }
}
