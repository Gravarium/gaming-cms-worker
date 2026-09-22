<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921225000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prevent media deletion from silently detaching guild and video references';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql('ALTER TABLE guild DROP CONSTRAINT FK_GUILD_LOGO');
        $this->addSql('ALTER TABLE guild ADD CONSTRAINT FK_GUILD_LOGO FOREIGN KEY (logo_id) REFERENCES media_asset (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE video DROP CONSTRAINT FK_VIDEO_MEDIA');
        $this->addSql('ALTER TABLE video ADD CONSTRAINT FK_VIDEO_MEDIA FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql('ALTER TABLE guild DROP CONSTRAINT FK_GUILD_LOGO');
        $this->addSql('ALTER TABLE guild ADD CONSTRAINT FK_GUILD_LOGO FOREIGN KEY (logo_id) REFERENCES media_asset (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE video DROP CONSTRAINT FK_VIDEO_MEDIA');
        $this->addSql('ALTER TABLE video ADD CONSTRAINT FK_VIDEO_MEDIA FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
