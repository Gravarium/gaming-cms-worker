<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add member profile privacy, invitation lifecycle and retained account deletion workflow';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql("CREATE TABLE member_profile (
            user_id INT NOT NULL,
            avatar_asset_id INT DEFAULT NULL,
            banner_asset_id INT DEFAULT NULL,
            bio TEXT DEFAULT NULL,
            visibility JSON NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(user_id),
            CONSTRAINT fk_member_profile_user FOREIGN KEY (user_id) REFERENCES cms_user (id) ON DELETE CASCADE,
            CONSTRAINT fk_member_profile_avatar FOREIGN KEY (avatar_asset_id) REFERENCES media_asset (id) ON DELETE SET NULL,
            CONSTRAINT fk_member_profile_banner FOREIGN KEY (banner_asset_id) REFERENCES media_asset (id) ON DELETE SET NULL
        )");
        $this->addSql('CREATE INDEX idx_member_profile_avatar ON member_profile (avatar_asset_id)');
        $this->addSql('CREATE INDEX idx_member_profile_banner ON member_profile (banner_asset_id)');

        $this->addSql("CREATE TABLE profile_deletion_request (
            user_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            execute_after TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(user_id),
            CONSTRAINT fk_profile_deletion_user FOREIGN KEY (user_id) REFERENCES cms_user (id) ON DELETE CASCADE,
            CONSTRAINT chk_profile_deletion_status CHECK (status IN ('pending', 'cancelled', 'completed'))
        )");
        $this->addSql('CREATE INDEX idx_profile_deletion_due ON profile_deletion_request (status, execute_after)');

        $this->addSql("CREATE TABLE member_invitation (
            id SERIAL NOT NULL,
            created_by_id INT NOT NULL,
            access_role_id INT DEFAULT NULL,
            accepted_user_id INT DEFAULT NULL,
            email VARCHAR(180) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            role_permission_snapshot JSON NOT NULL,
            status VARCHAR(20) NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_member_invitation_creator FOREIGN KEY (created_by_id) REFERENCES cms_user (id) ON DELETE RESTRICT,
            CONSTRAINT fk_member_invitation_role FOREIGN KEY (access_role_id) REFERENCES access_role (id) ON DELETE SET NULL,
            CONSTRAINT fk_member_invitation_accepted_user FOREIGN KEY (accepted_user_id) REFERENCES cms_user (id) ON DELETE SET NULL,
            CONSTRAINT chk_member_invitation_status CHECK (status IN ('pending', 'accepted', 'revoked'))
        )");
        $this->addSql('CREATE UNIQUE INDEX uniq_member_invitation_token ON member_invitation (token_hash)');
        $this->addSql('CREATE INDEX idx_member_invitation_creator_created ON member_invitation (created_by_id, created_at)');
        $this->addSql('CREATE INDEX idx_member_invitation_email_status ON member_invitation (email, status, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('DROP TABLE member_invitation');
        $this->addSql('DROP TABLE profile_deletion_request');
        $this->addSql('DROP TABLE member_profile');
    }
}
