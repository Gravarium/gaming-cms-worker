<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917003000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add granular CMS permissions'; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql("ALTER TABLE cms_user ADD permissions JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE cms_user ALTER permissions DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cms_user DROP permissions');
    }
}
