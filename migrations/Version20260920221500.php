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
        $this->addSql("ALTER TABLE media_asset ADD deletion_state VARCHAR(12) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE media_asset ADD deletion_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN media_asset.deletion_requested_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX IDX_MEDIA_ASSET_DELETION_STATE ON media_asset (deletion_state, deletion_requested_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql('ALTER TABLE media_folder DROP CONSTRAINT FK_MEDIA_FOLDER_PARENT');
        $this->addSql('DROP INDEX IDX_MEDIA_ASSET_DELETION_STATE');
        $this->addSql('ALTER TABLE media_asset DROP deletion_state');
        $this->addSql('ALTER TABLE media_asset DROP deletion_requested_at');
        $this->addSql('ALTER TABLE media_folder ADD CONSTRAINT FK_MEDIA_FOLDER_PARENT FOREIGN KEY (parent_id) REFERENCES media_folder (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
