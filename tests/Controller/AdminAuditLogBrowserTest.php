<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminAuditLogBrowserTest extends WebTestCase
{
    public function testAuditViewerCanSearchActionSummaryAndActorEmailWithoutContext(): void
    {
        $client = static::createClient();
        $marker = 'audit-search-'.bin2hex(random_bytes(5));
        $viewer = $this->createUser($client, 'viewer-'.$marker.'@example.test', [CmsPermission::AUDIT]);
        $other = $this->createUser($client, 'other-'.$marker.'@example.test', []);

        $entityManager = $this->entityManager($client);
        $this->persistLog($entityManager, $other, 'Guild.Member.Changed', 'Roster edit completed');
        $this->persistLog($entityManager, $other, 'content.note', 'Curated search summary');
        $secretContext = 'private-context-'.$marker;
        $this->persistLog($entityManager, $viewer, 'account.security.changed', 'Unrelated event', ['secret' => $secretContext]);
        $this->persistLog($entityManager, $other, 'video.uploaded', 'A separate event');
        $entityManager->flush();

        $client->loginUser($viewer);

        $crawler = $client->request('GET', '/admin/audit-log', ['q' => 'GUILD.MEMBER.CHANGED']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.audit-log-count', '1 Änderung');
        self::assertSame(1, $crawler->filter('tbody tr')->count());
        self::assertStringContainsString('Roster edit completed', $crawler->filter('tbody tr')->text());

        $crawler = $client->request('GET', '/admin/audit-log', ['q' => 'curated search']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('tbody tr')->count());
        self::assertStringContainsString('Curated search summary', $crawler->filter('tbody tr')->text());

        $crawler = $client->request('GET', '/admin/audit-log', ['q' => $viewer->getEmail()]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('tbody tr')->count());
        self::assertStringContainsString('Unrelated event', $crawler->filter('tbody tr')->text());

        $crawler = $client->request('GET', '/admin/audit-log');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($secretContext, (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/admin/audit-log', ['q' => $secretContext]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.audit-log-count', '0 Änderungen');
        self::assertStringNotContainsString($secretContext, (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Keine passenden Änderungen gefunden', $crawler->filter('tbody tr')->text());
    }

    public function testAuditViewerCanReadStablePagesOfMatchingEntries(): void
    {
        $client = static::createClient();
        $marker = 'audit-page-'.bin2hex(random_bytes(5));
        $viewer = $this->createUser($client, 'pagination-'.$marker.'@example.test', [CmsPermission::AUDIT]);

        $entityManager = $this->entityManager($client);
        for ($index = 1; $index <= 52; ++$index) {
            $this->persistLog($entityManager, $viewer, 'content.updated', $marker.' event-'.$index);
        }
        $entityManager->flush();
        $client->loginUser($viewer);

        $crawler = $client->request('GET', '/admin/audit-log', ['q' => $marker]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.audit-log-count', '52 Änderungen');
        self::assertSame(50, $crawler->filter('tbody tr')->count());
        self::assertStringContainsString('event-52', $crawler->filter('tbody tr')->eq(0)->text());
        self::assertStringContainsString('event-3', $crawler->filter('tbody tr')->eq(49)->text());
        self::assertSelectorTextContains('.pagination', 'Seite 1 von 2');
        self::assertSame(1, $crawler->filter('.pagination a[href*="page=2"]')->count());

        $crawler = $client->request('GET', '/admin/audit-log', ['q' => $marker, 'page' => '2']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('tbody tr')->count());
        self::assertStringContainsString('event-2', $crawler->filter('tbody tr')->eq(0)->text());
        self::assertStringContainsString('event-1', $crawler->filter('tbody tr')->eq(1)->text());
        self::assertSelectorTextContains('.pagination', 'Seite 2 von 2');

        $crawler = $client->request('GET', '/admin/audit-log', ['q' => $marker, 'page' => '999']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('tbody tr')->count());
        self::assertSelectorTextContains('.pagination', 'Seite 2 von 2');
    }

    public function testSearchInputIsBoundedAndMalformedQueryParametersAreIgnored(): void
    {
        $client = static::createClient();
        $viewer = $this->createUser($client, 'bounded-'.bin2hex(random_bytes(5)).'@example.test', [CmsPermission::AUDIT]);
        $client->loginUser($viewer);

        $crawler = $client->request('GET', '/admin/audit-log', [
            'q' => str_repeat('x', 101),
            'page' => '999999999999999999999999999999',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(100, mb_strlen((string) $crawler->filter('input[name="q"]')->attr('value')));
        self::assertSelectorExists('input[name="q"][maxlength="100"]');
        self::assertSelectorTextContains('.pagination', 'Seite 1 von 1');

        $crawler = $client->request('GET', '/admin/audit-log', [
            'q' => ['unexpected' => 'array'],
            'page' => ['not' => 'a scalar'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $crawler->filter('input[name="q"]')->attr('value'));
        self::assertSelectorTextContains('.pagination', 'Seite 1 von 1');
    }

    public function testAuditLogRequiresTheAuditPermission(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/audit-log');

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedUserWithoutAuditPermissionIsDenied(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'denied-'.bin2hex(random_bytes(5)).'@example.test', []);
        $client->loginUser($user);

        $client->request('GET', '/admin/audit-log');

        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $email, array $permissions): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('Audit browser user')
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);

        $entityManager = $this->entityManager($client);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /** @param array<string, mixed> $context */
    private function persistLog(EntityManagerInterface $entityManager, User $actor, string $action, string $summary, array $context = []): void
    {
        $entityManager->persist(
            (new AuditLog())
                ->setActor($actor)
                ->setAction($action)
                ->setSubjectType('AuditLogBrowserTest')
                ->setSummary($summary)
                ->setContext($context)
                ->setIpAddress('192.0.2.10'),
        );
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
