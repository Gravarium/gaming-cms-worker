<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920221500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prevent concurrent deletion of media folders that gain child folders';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql('ALTER TABLE media_folder DROP CONSTRAINT FK_MEDIA_FOLDER_PARENT');
        $this->addSql('ALTER TABLE media_folder ADD CONSTRAINT FK_MEDIA_FOLDER_PARENT FOREIGN KEY (parent_id) REFERENCES media_folder (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql('ALTER TABLE media_folder DROP CONSTRAINT FK_MEDIA_FOLDER_PARENT');
        $this->addSql('ALTER TABLE media_folder ADD CONSTRAINT FK_MEDIA_FOLDER_PARENT FOREIGN KEY (parent_id) REFERENCES media_folder (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
