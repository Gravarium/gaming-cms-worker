<?php

declare(strict_types=1);

namespace App\Tests\Controller\NotificationPreferences;

use App\Entity\User;
use App\Notification\Preferences\NotificationSubscriptionStore;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationSubscriptionOwnershipTest extends WebTestCase
{
    public function testTopicActionsRequireCsrfAndOnlyMutateTheSignedInUsersSubscription(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $connection = $client->getContainer()->get(Connection::class);
        $schemaManager = $connection->createSchemaManager();
        $testTables = [
            'notification_preference' => 'CREATE TABLE notification_preference (user_id INTEGER NOT NULL PRIMARY KEY, in_app_enabled BOOLEAN NOT NULL DEFAULT TRUE, email_enabled BOOLEAN NOT NULL DEFAULT TRUE, mentions_enabled BOOLEAN NOT NULL DEFAULT TRUE, subscriptions_enabled BOOLEAN NOT NULL DEFAULT TRUE, digest_frequency VARCHAR(16) NOT NULL DEFAULT \'immediate\', quiet_hours_start VARCHAR(5) DEFAULT NULL, quiet_hours_end VARCHAR(5) DEFAULT NULL, timezone VARCHAR(64) NOT NULL DEFAULT \'UTC\', updated_at TIMESTAMP NOT NULL)',
            'notification_subscription' => 'CREATE TABLE notification_subscription (user_id INTEGER NOT NULL, topic VARCHAR(128) NOT NULL, created_at TIMESTAMP NOT NULL, PRIMARY KEY (user_id, topic))',
        ];
        $createdTables = [];

        try {
            foreach ($testTables as $table => $ddl) {
                if (!$schemaManager->tablesExist([$table])) {
                    $connection->executeStatement($ddl);
                    $createdTables[] = $table;
                }
            }

            $suffix = bin2hex(random_bytes(6));
            $actor = (new User())
                ->setEmail('notification-actor-'.$suffix.'@example.test')
                ->setDisplayName('Notification actor')
                ->setPermissions([CmsPermission::ACCESS])
                ->verifyEmail();
            $otherUser = (new User())
                ->setEmail('notification-other-'.$suffix.'@example.test')
                ->setDisplayName('Other notification user')
                ->setPermissions([CmsPermission::ACCESS])
                ->verifyEmail();

            $entityManager->persist($actor);
            $entityManager->persist($otherUser);
            $entityManager->flush();

            $actorId = $actor->getId();
            $otherUserId = $otherUser->getId();
            self::assertNotNull($actorId);
            self::assertNotNull($otherUserId);

            $subscriptions = $client->getContainer()->get(NotificationSubscriptionStore::class);
            $topic = 'guild:thread:security-boundary';
            $rejectedTopic = 'guild:thread:csrf-boundary';
            $now = new \DateTimeImmutable();
            $subscriptions->subscribe($actorId, $topic, $now);
            $subscriptions->subscribe($otherUserId, $topic, $now);

            $client->loginUser($actor);
            $crawler = $client->request('GET', '/account/notifications');
            self::assertResponseIsSuccessful();
            $unsubscribeForm = $crawler->filter('form[action="/account/notifications/topics/unsubscribe"]');
            self::assertCount(1, $unsubscribeForm);
            $token = $unsubscribeForm->filter('input[name="_token"]')->attr('value');
            self::assertNotNull($token);

            $client->request('POST', '/account/notifications/topics/subscribe', [
                '_token' => 'invalid-token',
                'topic' => $rejectedTopic,
                'user_id' => $otherUserId,
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertFalse($subscriptions->isSubscribed($actorId, $rejectedTopic));
            self::assertFalse($subscriptions->isSubscribed($otherUserId, $rejectedTopic));
            self::assertTrue($subscriptions->isSubscribed($otherUserId, $topic));

            $client->request('POST', '/account/notifications/topics/unsubscribe', [
                '_token' => $token,
                'topic' => $topic,
                'user_id' => $otherUserId,
            ]);
            self::assertResponseRedirects('/account/notifications');
            self::assertFalse($subscriptions->isSubscribed($actorId, $topic));
            self::assertTrue($subscriptions->isSubscribed($otherUserId, $topic));
        } finally {
            foreach (array_reverse($createdTables) as $table) {
                $connection->executeStatement('DROP TABLE '.$table);
            }
        }
    }
}
