<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924134000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification preferences, deduplication, topic subscriptions, and consent-based newsletter workflow tables';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        $this->addSql("CREATE TABLE notification_preference (
            user_id INT NOT NULL,
            in_app_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            email_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            mentions_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            subscriptions_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            digest_frequency VARCHAR(16) NOT NULL DEFAULT 'immediate',
            quiet_hours_start VARCHAR(5) DEFAULT NULL,
            quiet_hours_end VARCHAR(5) DEFAULT NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(user_id),
            CONSTRAINT fk_notification_preference_user FOREIGN KEY (user_id) REFERENCES cms_user (id) ON DELETE CASCADE,
            CONSTRAINT chk_notification_preference_digest CHECK (digest_frequency IN ('immediate', 'daily', 'weekly', 'off')),
            CONSTRAINT chk_notification_preference_quiet_pair CHECK ((quiet_hours_start IS NULL AND quiet_hours_end IS NULL) OR (quiet_hours_start IS NOT NULL AND quiet_hours_end IS NOT NULL))
        )");
        $this->addSql("CREATE TABLE notification_deduplication (
            user_id INT NOT NULL,
            dedupe_hash CHAR(64) NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(user_id, dedupe_hash),
            CONSTRAINT fk_notification_dedup_user FOREIGN KEY (user_id) REFERENCES cms_user (id) ON DELETE CASCADE
        )");
        $this->addSql('CREATE INDEX idx_notification_dedup_expires ON notification_deduplication (expires_at)');
        $this->addSql("CREATE TABLE notification_subscription (
            user_id INT NOT NULL,
            topic VARCHAR(128) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(user_id, topic),
            CONSTRAINT fk_notification_subscription_user FOREIGN KEY (user_id) REFERENCES cms_user (id) ON DELETE CASCADE
        )");

        $this->addSql("CREATE TABLE newsletter_subscription (
            id SERIAL NOT NULL,
            user_id INT DEFAULT NULL,
            email VARCHAR(180) NOT NULL,
            status VARCHAR(20) NOT NULL,
            consent_source VARCHAR(80) NOT NULL,
            consent_version VARCHAR(32) NOT NULL,
            confirmation_token_hash CHAR(64) DEFAULT NULL,
            confirmation_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            unsubscribe_token_hash CHAR(64) DEFAULT NULL,
            unsubscribed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            suppressed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            suppression_reason VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_newsletter_subscription_user FOREIGN KEY (user_id) REFERENCES cms_user (id) ON DELETE SET NULL,
            CONSTRAINT chk_newsletter_subscription_status CHECK (status IN ('pending', 'active', 'unsubscribed', 'suppressed'))
        )");
        $this->addSql('CREATE UNIQUE INDEX uniq_newsletter_subscription_email ON newsletter_subscription (email)');
        $this->addSql('CREATE INDEX idx_newsletter_subscription_status ON newsletter_subscription (status)');

        $this->addSql("CREATE TABLE newsletter_campaign (
            id SERIAL NOT NULL,
            created_by_id INT NOT NULL,
            title VARCHAR(160) NOT NULL,
            subject VARCHAR(180) NOT NULL,
            body_text TEXT NOT NULL,
            segment VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            max_recipients INT NOT NULL DEFAULT 500,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_newsletter_campaign_created_by FOREIGN KEY (created_by_id) REFERENCES cms_user (id) ON DELETE RESTRICT,
            CONSTRAINT chk_newsletter_campaign_segment CHECK (segment IN ('all', 'members', 'guests')),
            CONSTRAINT chk_newsletter_campaign_status CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled')),
            CONSTRAINT chk_newsletter_campaign_quota CHECK (max_recipients BETWEEN 1 AND 10000)
        )");
        $this->addSql('CREATE INDEX idx_newsletter_campaign_schedule ON newsletter_campaign (status, scheduled_at)');

        $this->addSql("CREATE TABLE newsletter_delivery (
            id SERIAL NOT NULL,
            campaign_id INT NOT NULL,
            subscription_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            retry_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            last_failure_code VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_newsletter_delivery_campaign FOREIGN KEY (campaign_id) REFERENCES newsletter_campaign (id) ON DELETE CASCADE,
            CONSTRAINT fk_newsletter_delivery_subscription FOREIGN KEY (subscription_id) REFERENCES newsletter_subscription (id) ON DELETE CASCADE,
            CONSTRAINT chk_newsletter_delivery_status CHECK (status IN ('pending', 'retry', 'sent', 'failed', 'suppressed'))
        )");
        $this->addSql('CREATE UNIQUE INDEX uniq_newsletter_delivery_pair ON newsletter_delivery (campaign_id, subscription_id)');
        $this->addSql('CREATE INDEX idx_newsletter_delivery_retry ON newsletter_delivery (campaign_id, status, retry_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('DROP TABLE newsletter_delivery');
        $this->addSql('DROP TABLE newsletter_campaign');
        $this->addSql('DROP TABLE newsletter_subscription');
        $this->addSql('DROP TABLE notification_subscription');
        $this->addSql('DROP TABLE notification_deduplication');
        $this->addSql('DROP TABLE notification_preference');
    }
}
