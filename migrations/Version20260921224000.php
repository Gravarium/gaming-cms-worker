<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921224000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce category deletion integrity for child categories and content references';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql('ALTER TABLE content_entry DROP CONSTRAINT FK_CONTENT_ENTRY_CATEGORY');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_CONTENT_ENTRY_CATEGORY FOREIGN KEY (category_id) REFERENCES content_category (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE content_category DROP CONSTRAINT FK_CONTENT_CATEGORY_PARENT');
        $this->addSql('ALTER TABLE content_category ADD CONSTRAINT FK_CONTENT_CATEGORY_PARENT FOREIGN KEY (parent_id) REFERENCES content_category (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql('ALTER TABLE content_entry DROP CONSTRAINT FK_CONTENT_ENTRY_CATEGORY');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_CONTENT_ENTRY_CATEGORY FOREIGN KEY (category_id) REFERENCES content_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE content_category DROP CONSTRAINT FK_CONTENT_CATEGORY_PARENT');
        $this->addSql('ALTER TABLE content_category ADD CONSTRAINT FK_CONTENT_CATEGORY_PARENT FOREIGN KEY (parent_id) REFERENCES content_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
