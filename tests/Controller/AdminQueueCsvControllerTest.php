<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminQueueCsvControllerTest extends WebTestCase
{
    private string $marker;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = 'queue-csv-'.bin2hex(random_bytes(10));
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE queue_name = ? AND body LIKE ?',
                ['failed', '%'.$this->marker.'%'],
            );
            $this->connection->executeStatement(
                'DELETE FROM cms_user WHERE email LIKE ?',
                ['queue-'.$this->marker.'-%'],
            );
        }

        parent::tearDown();
    }

    public function testAuthorizedManagerDownloadsOnlyTheRequestedBoundedPage(): void
    {
        $client = static::createClient();
        $connection = $this->ensureQueueTable($client);
        $client->loginUser($this->user($client));

        $createdIds = [];
        for ($index = 0; $index < 27; ++$index) {
            $createdIds[] = $this->insertRawFailure($connection, $index);
        }

        $client->request('GET', '/admin/queue?page=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/queue/export.csv?page=2"]');

        $client->request('GET', '/admin/queue/export.csv?page=2');
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('failed-message-queue-page-2.csv', (string) $response->headers->get('Content-Disposition'));
        $cacheControlDirectives = array_map('trim', explode(',', strtolower((string) $response->headers->get('Cache-Control'))));
        self::assertContains('private', $cacheControlDirectives);
        self::assertContains('no-store', $cacheControlDirectives);
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $csv = (string) $response->getContent();
        $rows = $this->csvRows($csv);
        self::assertCount(3, $rows);
        self::assertSame(['Message ID', 'Nachrichtentyp', 'Fehlgeschlagen seit (UTC)', 'Nutzlastgröße (Bytes)'], $rows[0]);
        self::assertSame(array_reverse(array_slice($createdIds, 0, 2)), array_column(array_slice($rows, 1), 0));
        self::assertSame('FailedQueueCsvMessage', $rows[1][1]);
        self::assertStringNotContainsString($this->marker, $csv);
        self::assertStringNotContainsString('recipient-', $csv);
        self::assertStringNotContainsString('Bearer secret', $csv);
        self::assertStringNotContainsString('private-payload-', $csv);
        self::assertStringNotContainsString('technical-error', $csv);
    }

    public function testInvalidPageAndUnexpectedQueryValuesReturnGenericBadRequest(): void
    {
        $client = static::createClient();
        $connection = $this->ensureQueueTable($client);
        $client->loginUser($this->user($client));
        $this->insertRawFailure($connection, 0);

        foreach ([
            '/admin/queue/export.csv?page[]=sentinel',
            '/admin/queue/export.csv?page=01',
            '/admin/queue/export.csv?secret=sentinel',
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('Die Seitennummer ist ungültig.', $client->getResponse()->getContent());
            self::assertStringNotContainsString('sentinel', (string) $client->getResponse()->getContent());
            self::assertSame('noindex', $client->getResponse()->headers->get('X-Robots-Tag'));
        }
    }

    public function testManagerWithoutSettingsPermissionCannotDownloadTheQueue(): void
    {
        $client = static::createClient();
        $this->ensureQueueTable($client);
        $user = (new User())
            ->setEmail('queue-'.$this->marker.'-'.bin2hex(random_bytes(3)).'@example.test')
            ->setDisplayName('Queue CSV test')
            ->setPassword('not-a-real-login-hash')
            ->setPermissions([CmsPermission::CONTENT]);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($user);
        $entityManager->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/queue/export.csv');

        self::assertResponseStatusCodeSame(403);
    }

    private function ensureQueueTable(KernelBrowser $client): Connection
    {
        $connection = $client->getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
            $schema = new Schema();
            $table = $schema->createTable('messenger_messages');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $table->addColumn('body', Types::TEXT);
            $table->addColumn('headers', Types::TEXT);
            $table->addColumn('queue_name', Types::STRING, ['length' => 190]);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('available_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $connection->createSchemaManager()->createTable($table);
        }

        return $connection;
    }

    private function insertRawFailure(Connection $connection, int $index): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $connection->insert('messenger_messages', [
            'body' => sprintf('private-payload-%s-%d-%s', $this->marker, $index, str_repeat('x', 48)),
            'headers' => json_encode([
                'type' => FailedQueueCsvMessage::class,
                'recipient' => 'recipient-'.$this->marker.'@example.test',
                'authorization' => 'Bearer secret-'.$this->marker,
                'exception' => 'private-technical-error-'.$this->marker,
            ], JSON_THROW_ON_ERROR),
            'queue_name' => 'failed',
            'created_at' => $now,
            'available_at' => $now,
            'delivered_at' => null,
        ]);

        return (string) $connection->lastInsertId();
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('queue-'.$this->marker.'-'.bin2hex(random_bytes(3)).'@example.test')
            ->setDisplayName('Queue CSV manager')
            ->setPassword('not-a-real-login-hash')
            ->setPermissions([CmsPermission::SETTINGS]);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /** @return list<list<string>> */
    private function csvRows(string $csv): array
    {
        $lines = array_filter(explode("\r\n", rtrim($csv, "\r\n")), static fn (string $line): bool => $line !== '');

        return array_values(array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines));
    }
}

final readonly class FailedQueueCsvMessage
{
    public function __construct(public string $marker)
    {
    }
}
