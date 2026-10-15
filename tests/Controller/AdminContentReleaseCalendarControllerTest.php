<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentRelease;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminContentReleaseCalendarControllerTest extends WebTestCase
{
    private string $marker;
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = 'release-calendar-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement(
                'DELETE FROM content_release_entry WHERE content_release_id IN (SELECT id FROM content_release WHERE name LIKE ?)',
                [$this->marker.'-%'],
            );
            $this->connection->executeStatement('DELETE FROM content_release WHERE name LIKE ?', [$this->marker.'-%']);
            $this->connection->executeStatement('DELETE FROM cms_user WHERE email LIKE ?', [$this->marker.'-%@example.test']);
        }

        parent::tearDown();
    }

    public function testCalendarListsScheduledAndPublishedReleasesForSelectedMonth(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $scheduled = $this->release($client, $admin, 'scheduled', ContentRelease::STATUS_SCHEDULED);
        $scheduled->setScheduledAt(new \DateTimeImmutable('2032-03-09 14:30:00', new \DateTimeZone('UTC')));
        $published = $this->release($client, $admin, 'published', ContentRelease::STATUS_DRAFT);
        $draft = $this->release($client, $admin, 'draft', ContentRelease::STATUS_DRAFT);
        $cancelled = $this->release($client, $admin, 'cancelled', ContentRelease::STATUS_CANCELLED);
        $this->em($client)->flush();
        $this->connection = $this->em($client)->getConnection();
        $this->connection->executeStatement(
            'UPDATE content_release SET status = ?, published_at = ? WHERE id = ?',
            [ContentRelease::STATUS_PUBLISHED, '2032-03-09 15:45:00', $published->getId()],
        );
        $this->em($client)->clear();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/content/releases/calendar?month=2032-03');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Release-Kalender');
        self::assertSelectorExists('tr[data-calendar-date="2032-03-09"]');
        self::assertSelectorTextContains('tr[data-calendar-date="2032-03-09"]', '14:30 UTC');
        self::assertSelectorTextContains('tr[data-calendar-date="2032-03-09"]', '15:45 UTC');
        self::assertSelectorTextContains('tr[data-calendar-date="2032-03-09"]', $this->marker.'-scheduled');
        self::assertSelectorTextContains('tr[data-calendar-date="2032-03-09"]', $this->marker.'-published');
        self::assertSelectorTextNotContains('body', $this->marker.'-draft');
        self::assertSelectorTextNotContains('body', $this->marker.'-cancelled');
        self::assertSame('/admin/content/releases/calendar?month=2032-02', $crawler->filter('a[rel="prev"]')->attr('href'));
        self::assertSame('/admin/content/releases/calendar?month=2032-04', $crawler->filter('a[rel="next"]')->attr('href'));
        self::assertCount(1, $crawler->filter('a[href="/admin/content/releases/'.$scheduled->getId().'/edit"]'));
        self::assertCount(0, $crawler->filter('a[href="/admin/content/releases/'.$published->getId().'/edit"]'));
        self::assertNotNull($draft->getId());
        self::assertNotNull($cancelled->getId());
    }

    public function testInvalidMonthIsGenericUncachedBadRequest(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $client->loginUser($admin);

        foreach (['2032-13', '2032-3', ['2032-03']] as $month) {
            $client->request('GET', '/admin/content/releases/calendar', ['month' => $month]);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('Ungültiger Monatsparameter.', $client->getResponse()->getContent());
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
        }

    }

    public function testContentPermissionIsRequiredForCalendar(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, false));
        $client->request('GET', '/admin/content/releases/calendar');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCalendarCapsResultsAtOneHundredAndSignalsTruncation(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $scheduled = [];
        for ($index = 1; $index <= 101; ++$index) {
            $release = $this->release($client, $admin, sprintf('bounded-%03d', $index), ContentRelease::STATUS_SCHEDULED);
            $release->setScheduledAt(new \DateTimeImmutable('2032-04-15 12:00:00', new \DateTimeZone('UTC')));
            $scheduled[] = $release;
        }
        $this->em($client)->flush();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/content/releases/calendar?month=2032-04');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'begrenzt');
        self::assertCount(100, $crawler->filter('.calendar-event'));
        self::assertSame('/admin/content/releases/'.$scheduled[0]->getId().'/edit', $crawler->filter('.calendar-event a')->first()->attr('href'));
        self::assertSame('/admin/content/releases/'.$scheduled[99]->getId().'/edit', $crawler->filter('.calendar-event a')->last()->attr('href'));
    }

    private function user(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, bool $canManageContent): User
    {
        $this->connection = $this->em($client)->getConnection();
        $user = (new User())
            ->setEmail($this->marker.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Release calendar test')
            ->setPermissions($canManageContent ? [CmsPermission::CONTENT] : [])
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function release(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, User $admin, string $suffix, string $status): ContentRelease
    {
        $release = (new ContentRelease())
            ->setName($this->marker.'-'.$suffix)
            ->setCreatedBy($admin)
            ->setStatus($status);
        $this->em($client)->persist($release);

        return $release;
    }

    private function em(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
