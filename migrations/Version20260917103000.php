<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260917103000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add WebAuthn passkey credentials'; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('CREATE TABLE passkey_credential (id VARCHAR(36) NOT NULL, name VARCHAR(80) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, public_key_credential_id TEXT NOT NULL, type VARCHAR(255) NOT NULL, transports JSON NOT NULL, attestation_type VARCHAR(255) NOT NULL, trust_path JSON NOT NULL, aaguid VARCHAR(36) NOT NULL, credential_public_key TEXT NOT NULL, user_handle VARCHAR(255) NOT NULL, counter INT NOT NULL, other_ui JSON DEFAULT NULL, backup_eligible BOOLEAN DEFAULT NULL, backup_status BOOLEAN DEFAULT NULL, uv_initialized BOOLEAN DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_passkey_credential_id ON passkey_credential (public_key_credential_id)');
        $this->addSql('CREATE INDEX idx_passkey_user_handle ON passkey_credential (user_handle)');
        $this->addSql("COMMENT ON COLUMN passkey_credential.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN passkey_credential.last_used_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN passkey_credential.public_key_credential_id IS '(DC2Type:base64)'");
        $this->addSql("COMMENT ON COLUMN passkey_credential.trust_path IS '(DC2Type:trust_path)'");
        $this->addSql("COMMENT ON COLUMN passkey_credential.aaguid IS '(DC2Type:aaguid)'");
        $this->addSql("COMMENT ON COLUMN passkey_credential.credential_public_key IS '(DC2Type:base64)'");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE passkey_credential'); }
}
