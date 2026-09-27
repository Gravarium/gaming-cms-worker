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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Mime\Email;

final class AdminQueuePaginationTest extends WebTestCase
{
    private string $marker;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = 'queue-pagination-'.bin2hex(random_bytes(10));
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

    public function testQueueRequiresItsSettingsPermissionAndRendersForAnAuthorizedUser(): void
    {
        $client = static::createClient();
        $this->ensureQueueTable($client);
        $client->loginUser($this->user($client, [CmsPermission::CONTENT]));

        $client->request('GET', '/admin/queue');
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($this->user($client, [CmsPermission::SETTINGS]));
        $client->request('GET', '/admin/queue');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nachrichten-Warteschlange');
    }

    public function testPagesCoverEveryFailedMessageOnceAndKeepSensitivePayloadPrivate(): void
    {
        $client = static::createClient();
        $connection = $this->ensureQueueTable($client);
        $client->loginUser($this->user($client, [CmsPermission::SETTINGS]));

        for ($index = 0; $index < 53; ++$index) {
            $this->insertRawFailure($connection, $index);
        }

        $expectedIds = array_map(
            'strval',
            $connection->fetchFirstColumn('SELECT id FROM messenger_messages WHERE queue_name = ? ORDER BY id DESC', ['failed']),
        );
        $pageCount = max(1, (int) ceil(count($expectedIds) / 25));
        $observedIds = [];

        for ($page = 1; $page <= $pageCount; ++$page) {
            $crawler = $client->request('GET', '/admin/queue?page='.$page);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', sprintf('Seite %d von %d', $page, $pageCount));
            $observedIds = [...$observedIds, ...$this->messageIds($crawler)];

            if ($page === 1) {
                self::assertSelectorTextContains('body', sprintf('Einträge 1–25 von %d', count($expectedIds)));
                self::assertSelectorExists('a[rel="next"][href="/admin/queue?page=2"]');
                self::assertStringNotContainsString($this->marker, (string) $client->getResponse()->getContent());
                self::assertStringNotContainsString('recipient-'.$this->marker.'@example.test', (string) $client->getResponse()->getContent());
                self::assertStringNotContainsString('private-technical-error-'.$this->marker, (string) $client->getResponse()->getContent());
            }
        }

        self::assertSame($expectedIds, $observedIds, 'Pagination must not omit or repeat failed-message IDs.');

        $lastPage = $client->request('GET', '/admin/queue?page=999999999');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', sprintf('Seite %d von %d', $pageCount, $pageCount));
        self::assertStringNotContainsString('999999999', (string) $client->getResponse()->getContent());
        self::assertSame(array_slice($expectedIds, -min(25, count($expectedIds))), $this->messageIds($lastPage));

        $pageTwo = $client->request('GET', '/admin/queue?page=2');
        self::assertResponseIsSuccessful();
        $retryAllForm = $pageTwo->filter('form[action="/admin/queue/retry-all?page=2"]');
        self::assertCount(1, $retryAllForm);
        self::assertNotSame('', (string) $retryAllForm->filter('input[name="_token"]')->attr('value'));
        foreach ($pageTwo->filter('form[action*="/admin/queue/"][action*="/retry?page=2"]') as $form) {
            self::assertNotSame('', (string) (new Crawler($form))->filter('input[name="_token"]')->attr('value'));
        }
        self::assertSelectorExists('a[rel="prev"][href="/admin/queue?page=1"]');
    }

    public function testMalformedPageValuesFailWithoutEchoOrQueueMutation(): void
    {
        $client = static::createClient();
        $connection = $this->ensureQueueTable($client);
        $client->loginUser($this->user($client, [CmsPermission::SETTINGS]));
        $this->insertRawFailure($connection, 0);
        $before = (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', ['failed']);

        foreach ([
            '/admin/queue?page=0',
            '/admin/queue?page=01',
            '/admin/queue?page=-1',
            '/admin/queue?page=1.5',
            '/admin/queue?page=1234567890UNIQUE',
            '/admin/queue?page[]=array-sentinel',
        ] as $uri) {
            $client->request('GET', $uri);
            self::assertResponseStatusCodeSame(400);
            self::assertStringNotContainsString('1234567890UNIQUE', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('array-sentinel', (string) $client->getResponse()->getContent());
            self::assertSame($before, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', ['failed']));
        }
    }

    public function testRetryActionsKeepTheirCsrfChecksAndRetryAllOnlyTouchesTheVisiblePage(): void
    {
        $client = static::createClient();
        $connection = $this->ensureQueueTable($client);
        $client->loginUser($this->user($client, [CmsPermission::SETTINGS]));
        $failedTransport = $client->getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(SenderInterface::class, $failedTransport);

        $createdIds = [];
        for ($index = 0; $index < 27; ++$index) {
            $sent = $failedTransport->send($this->emailEnvelope($this->marker.'-'.$index));
            $stamp = $sent->last(TransportMessageIdStamp::class);
            self::assertInstanceOf(TransportMessageIdStamp::class, $stamp);
            $createdIds[] = (string) $stamp->getId();
        }

        $pageTwo = $client->request('GET', '/admin/queue?page=2');
        self::assertResponseIsSuccessful();
        $pageTwoIds = $this->messageIds($pageTwo);
        $pageTwoCreatedIds = array_values(array_intersect($createdIds, $pageTwoIds));
        self::assertCount(2, $pageTwoCreatedIds);

        $rowAction = sprintf('/admin/queue/%s/retry?page=2', $pageTwoCreatedIds[0]);
        $client->request('POST', $rowAction);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(27, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE ?',
            ['failed', '%'.$this->marker.'%'],
        ));

        $rowToken = (string) $pageTwo->filter(sprintf('form[action="%s"] input[name="_token"]', $rowAction))->attr('value');
        $client->request('POST', $rowAction, ['_token' => $rowToken]);
        self::assertResponseRedirects('/admin/queue?page=2');
        self::assertSame(26, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE ?',
            ['failed', '%'.$this->marker.'%'],
        ));

        $pageTwoAfterRowRetry = $client->request('GET', '/admin/queue?page=2');
        self::assertResponseIsSuccessful();
        $pageTwoRemainingCreatedIds = array_values(array_intersect($createdIds, $this->messageIds($pageTwoAfterRowRetry)));
        self::assertCount(1, $pageTwoRemainingCreatedIds);

        $client->request('POST', '/admin/queue/retry-all?page=2', ['_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(26, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE ?',
            ['failed', '%'.$this->marker.'%'],
        ));

        $token = (string) $pageTwoAfterRowRetry->filter('form[action="/admin/queue/retry-all?page=2"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/queue/retry-all?page=2', ['_token' => $token]);
        self::assertResponseRedirects('/admin/queue?page=2');

        $remainingCreatedIds = array_map(
            'strval',
            $connection->fetchFirstColumn(
                'SELECT id FROM messenger_messages WHERE queue_name = ? AND body LIKE ? ORDER BY id ASC',
                ['failed', '%'.$this->marker.'%'],
            ),
        );
        self::assertSame(array_values(array_diff($createdIds, [$pageTwoCreatedIds[0], ...$pageTwoRemainingCreatedIds])), $remainingCreatedIds);
    }

    private function ensureQueueTable(KernelBrowser $client): Connection
    {
        $connection = $client->getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        if (!$connection->createSchemaManager()->tablesExist('messenger_messages')) {
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

    private function emailEnvelope(string $marker): Envelope
    {
        $email = (new Email())
            ->from('worker@example.test')
            ->to('recipient-'.$marker.'@example.test')
            ->subject('Queue retry pagination test')
            ->text('private payload '.$marker);

        return new Envelope(new SendEmailMessage($email));
    }

    private function insertRawFailure(Connection $connection, int $index): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $body = sprintf('private-payload-%s-%d-', $this->marker, $index).str_repeat('x', 96);
        $headers = json_encode([
            'type' => FailedQueuePaginationMessage::class,
            'recipient' => 'recipient-'.$this->marker.'@example.test',
            'authorization' => 'Bearer secret-'.$this->marker,
            'exception' => 'private-technical-error-'.$this->marker,
        ], JSON_THROW_ON_ERROR);
        $connection->insert('messenger_messages', [
            'body' => $body,
            'headers' => $headers,
            'queue_name' => 'failed',
            'created_at' => $now,
            'available_at' => $now,
            'delivered_at' => null,
        ]);

        return (string) $connection->lastInsertId();
    }

    /** @return list<string> */
    private function messageIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('[data-message-id]') as $node) {
            $id = (new Crawler($node))->attr('data-message-id');
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('queue-'.$this->marker.'-'.bin2hex(random_bytes(3)).'@example.test')
            ->setDisplayName('Queue pagination test')
            ->setPassword('not-a-real-login-hash')
            ->setPermissions($permissions);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}

final readonly class FailedQueuePaginationMessage
{
    public function __construct(public string $marker)
    {
    }
}
