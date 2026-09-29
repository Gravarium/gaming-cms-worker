<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ContentScheduleWorkflowTest extends WebTestCase
{
    private string $marker;

    private ?Connection $connection = null;

    private bool $contentStateSnapshotTaken = false;

    private bool $contentStateExisted = false;

    private ?bool $previousContentEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = 'content-schedule-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->contentStateSnapshotTaken) {
                if ($this->contentStateExisted) {
                    $this->connection->executeStatement(
                        'UPDATE cms_module_state SET enabled = ? WHERE module_key = ?',
                        [$this->previousContentEnabled, 'content'],
                    );
                } else {
                    $this->connection->executeStatement('DELETE FROM cms_module_state WHERE module_key = ?', ['content']);
                }
            }

            $this->connection->executeStatement(
                'DELETE FROM audit_log WHERE subject_type = ? AND subject_id IN (SELECT id FROM content_entry WHERE title LIKE ?)',
                [ContentEntry::class, $this->marker.'-%'],
            );
            $this->connection->executeStatement('DELETE FROM content_entry WHERE title LIKE ?', [$this->marker.'-%']);
            $this->connection->executeStatement('DELETE FROM cms_user WHERE email LIKE ?', [$this->marker.'-%@example.test']);
        }

        parent::tearDown();
    }

    public function testCalendarFiltersMonthsOrdersEventsAndBoundsPagination(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $em = $this->em($client);
        $selectedAt = new \DateTimeImmutable('2098-10-01 12:00:00');
        $scheduled = [];

        for ($number = 1; $number <= 51; ++$number) {
            $entry = $this->entry(
                $manager,
                sprintf('scheduled-%02d', $number),
                ContentEntry::STATUS_SCHEDULED,
                null,
                $selectedAt,
                null,
            );
            $scheduled[] = $entry;
            $em->persist($entry);
        }

        $unpublication = $this->entry(
            $manager,
            'unpublication',
            ContentEntry::STATUS_PUBLISHED,
            new \DateTimeImmutable('-1 day'),
            null,
            $selectedAt,
        );
        $otherMonth = $this->entry(
            $manager,
            'other-month',
            ContentEntry::STATUS_SCHEDULED,
            null,
            $selectedAt->modify('+1 month'),
            null,
        );
        $draft = $this->entry($manager, 'draft', ContentEntry::STATUS_DRAFT, null, $selectedAt, null);
        $review = $this->entry($manager, 'review', ContentEntry::STATUS_REVIEW, null, $selectedAt, null);
        foreach ([$unpublication, $otherMonth, $draft, $review] as $entry) {
            $em->persist($entry);
        }
        $em->flush();
        // Legacy rows can carry stale dates, but lifecycle status must still exclude them.
        $em->getConnection()->executeStatement(
            'UPDATE content_entry SET scheduled_at = ? WHERE id IN (?, ?)',
            [$selectedAt, $this->entryId($draft), $this->entryId($review)],
            [Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $expectedIds = array_map(fn (ContentEntry $entry): int => $this->entryId($entry), $scheduled);
        $expectedIds[] = $this->entryId($unpublication);
        sort($expectedIds, SORT_NUMERIC);
        $month = $selectedAt->format('Y-m');
        $client->loginUser($manager);

        $firstPage = $client->request('GET', '/admin/content/schedule?month='.$month);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSelectorTextContains('.muted', 'Termine 1–50 von 52');
        self::assertSame(array_slice($expectedIds, 0, 50), $this->eventIds($firstPage));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($this->marker.'-other-month', $html);
        self::assertStringNotContainsString($this->marker.'-draft', $html);
        self::assertStringNotContainsString($this->marker.'-review', $html);

        $nextPage = (string) $firstPage->filter('.pagination[aria-label="Kalenderseiten"] a[rel="next"]')->attr('href');
        self::assertStringContainsString('month='.$month, $nextPage);
        self::assertStringContainsString('page=2', $nextPage);

        $secondPage = $client->request('GET', '/admin/content/schedule?month='.$month.'&page=2');
        self::assertResponseIsSuccessful();
        self::assertSame(array_slice($expectedIds, 50), $this->eventIds($secondPage));
        self::assertSelectorTextContains('.muted', 'Termine 51–52 von 52');

        $client->request('GET', '/admin/content/schedule', ['month' => '2026-13']);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->request('GET', '/admin/content/schedule', ['month' => ['2026-09']]);
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/admin/content/schedule', ['month' => $month, 'page' => '01']);
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/admin/content/schedule', ['month' => $month, 'page' => '3']);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/content/schedule', ['month' => $month, 'page' => '10001']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuccessfulReschedulingCapturesPreChangeRevisionAndAudit(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $em = $this->em($client);
        $publicationAt = (new \DateTimeImmutable('+3 days'))->setTime(12, 0);
        $unpublicationAt = $publicationAt->modify('+5 days');
        $scheduledEntry = $this->entry($manager, 'publication', ContentEntry::STATUS_SCHEDULED, null, $publicationAt, null);
        $publishedEntry = $this->entry(
            $manager,
            'unpublication',
            ContentEntry::STATUS_PUBLISHED,
            (new \DateTimeImmutable('-1 day'))->setTime(12, 0),
            null,
            $unpublicationAt,
        );
        $em->persist($scheduledEntry);
        $em->persist($publishedEntry);
        $em->flush();
        $scheduledId = $this->entryId($scheduledEntry);
        $publishedId = $this->entryId($publishedEntry);
        $client->loginUser($manager);

        $newPublicationAt = $publicationAt->modify('+2 months')->setTime(14, 30);
        $publicationReason = 'Launch review needs more time.';
        $publicationForm = $this->formState($client, $scheduledId, 'publication', $publicationAt->format('Y-m'));
        $this->submitChange($client, $publicationForm, $newPublicationAt, $publicationReason, $publicationForm['token']);
        self::assertResponseRedirects();

        $em = $this->em($client);
        $em->clear();
        $storedScheduled = $em->find(ContentEntry::class, $scheduledId);
        self::assertInstanceOf(ContentEntry::class, $storedScheduled);
        self::assertSame(ContentEntry::STATUS_SCHEDULED, $storedScheduled->getStatus());
        $this->assertSameMinute($newPublicationAt, $storedScheduled->getScheduledAt());
        $this->assertChangeEvidence($em, $storedScheduled, 'publication', $publicationAt, $publicationReason);

        $newUnpublicationAt = $unpublicationAt->modify('+2 months')->setTime(16, 45);
        $unpublicationReason = 'Campaign dates have shifted.';
        $unpublicationForm = $this->formState($client, $publishedId, 'unpublication', $unpublicationAt->format('Y-m'));
        $this->submitChange($client, $unpublicationForm, $newUnpublicationAt, $unpublicationReason, $unpublicationForm['token']);
        self::assertResponseRedirects();

        $em = $this->em($client);
        $em->clear();
        $storedPublished = $em->find(ContentEntry::class, $publishedId);
        self::assertInstanceOf(ContentEntry::class, $storedPublished);
        self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedPublished->getStatus());
        $this->assertSameMinute($newUnpublicationAt, $storedPublished->getScheduledUnpublishAt());
        $this->assertChangeEvidence($em, $storedPublished, 'unpublication', $unpublicationAt, $unpublicationReason);
    }

    public function testInvalidCsrfReasonsWindowsAndStaleStateDoNotWrite(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $em = $this->em($client);
        $publicationAt = (new \DateTimeImmutable('+4 days'))->setTime(12, 0);
        $ordinary = $this->entry($manager, 'ordinary', ContentEntry::STATUS_SCHEDULED, null, $publicationAt, null);
        $boundedAt = $publicationAt->modify('+1 day');
        $boundedEnd = $publicationAt->modify('+2 days');
        $bounded = $this->entry($manager, 'bounded-publication', ContentEntry::STATUS_SCHEDULED, null, $boundedAt, $boundedEnd);
        $publishedAt = $publicationAt->modify('+10 days');
        $published = $this->entry(
            $manager,
            'bounded-unpublication',
            ContentEntry::STATUS_PUBLISHED,
            $publishedAt,
            null,
            $publishedAt->modify('+10 days'),
        );
        foreach ([$ordinary, $bounded, $published] as $entry) {
            $em->persist($entry);
        }
        $em->flush();

        $ordinaryId = $this->entryId($ordinary);
        $boundedId = $this->entryId($bounded);
        $publishedId = $this->entryId($published);
        $client->loginUser($manager);

        $form = $this->formState($client, $ordinaryId, 'publication', $publicationAt->format('Y-m'));
        $this->submitChange($client, $form, $publicationAt->modify('+20 days'), 'A valid reason for change.', 'invalid-csrf-token');
        self::assertResponseStatusCodeSame(422);

        $this->submitRejectedChange($client, $ordinaryId, 'publication', $publicationAt->format('Y-m'), (new \DateTimeImmutable('-1 day'))->setTime(12, 0), 'A valid reason for change.');
        $this->submitRejectedChange($client, $ordinaryId, 'publication', $publicationAt->format('Y-m'), $publicationAt->modify('+20 days'), '');
        $this->submitRejectedChange($client, $ordinaryId, 'publication', $publicationAt->format('Y-m'), $publicationAt->modify('+20 days'), str_repeat('r', 301));
        $this->submitRejectedChange($client, $boundedId, 'publication', $publicationAt->format('Y-m'), $boundedEnd->modify('+1 minute'), 'This publication would occur after unpublishing.');
        $this->submitRejectedChange($client, $publishedId, 'unpublication', $publicationAt->format('Y-m'), $publishedAt->modify('-1 day'), 'This end date precedes publication.');

        $staleForm = $this->formState($client, $ordinaryId, 'publication', $publicationAt->format('Y-m'));
        $externalAt = $publicationAt->modify('+40 days');
        $em = $this->em($client);
        $em->clear();
        $changedExternally = $em->find(ContentEntry::class, $ordinaryId);
        self::assertInstanceOf(ContentEntry::class, $changedExternally);
        $changedExternally->setScheduledAt($externalAt);
        $changedExternally->synchronizePublication();
        $em->flush();
        $em->clear();

        $this->submitChange($client, $staleForm, $externalAt->modify('+10 days'), 'A reason from a stale form.', $staleForm['token']);
        self::assertResponseRedirects();

        $em = $this->em($client);
        $em->clear();
        $storedOrdinary = $em->find(ContentEntry::class, $ordinaryId);
        $storedBounded = $em->find(ContentEntry::class, $boundedId);
        $storedPublished = $em->find(ContentEntry::class, $publishedId);
        self::assertInstanceOf(ContentEntry::class, $storedOrdinary);
        self::assertInstanceOf(ContentEntry::class, $storedBounded);
        self::assertInstanceOf(ContentEntry::class, $storedPublished);
        $this->assertSameMinute($externalAt, $storedOrdinary->getScheduledAt());
        $this->assertSameMinute($boundedAt, $storedBounded->getScheduledAt());
        $this->assertSameMinute($boundedEnd, $storedBounded->getScheduledUnpublishAt());
        $this->assertSameMinute($publishedAt->modify('+10 days'), $storedPublished->getScheduledUnpublishAt());

        foreach ([$ordinaryId, $boundedId, $publishedId] as $entryId) {
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM content_revision WHERE entry_id = ?', [$entryId]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM audit_log WHERE action = ? AND subject_id = ?',
                ['content.schedule.changed', $entryId],
            ));
        }
    }

    public function testAnonymousCannotViewCalendar(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content/schedule');
        self::assertResponseRedirects('/login');
    }

    public function testUserWithoutContentPermissionCannotViewCalendar(): void
    {
        $client = static::createClient();
        $reader = $this->user($client, [CmsPermission::ACCESS]);
        $client->loginUser($reader);
        $client->request('GET', '/admin/content/schedule');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCalendarHonorsMethodBoundaryAndDisabledContentModule(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($manager);
        $client->request('PUT', '/admin/content/schedule/999/publication/change');
        self::assertResponseStatusCodeSame(405);

        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('content');
        $this->contentStateSnapshotTaken = true;
        $this->contentStateExisted = $state instanceof CmsModuleState;
        $this->previousContentEnabled = $state instanceof CmsModuleState ? $state->isEnabled() : null;
        $state ??= (new CmsModuleState())->setModuleKey('content');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        $client->request('GET', '/admin/content/schedule');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail($this->marker.'-user-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Content schedule manager')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(
        User $author,
        string $suffix,
        string $status,
        ?\DateTimeImmutable $publishedAt,
        ?\DateTimeImmutable $scheduledAt,
        ?\DateTimeImmutable $scheduledUnpublishAt,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($this->marker.'-'.$suffix)
            ->setSlug($this->marker.'-'.$suffix)
            ->setExcerpt('Excerpt '.$suffix)
            ->setBody('Body '.$suffix)
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setScheduledAt($scheduledAt)
            ->setScheduledUnpublishAt($scheduledUnpublishAt);
        $entry->synchronizePublication();

        return $entry;
    }

    /** @return array{action: string, token: string, expectedAt: string} */
    private function formState(KernelBrowser $client, int $entryId, string $kind, string $month): array
    {
        $crawler = $client->request(
            'GET',
            sprintf('/admin/content/schedule/%d/%s/change?month=%s', $entryId, $kind, rawurlencode($month)),
        );
        self::assertResponseIsSuccessful();

        return [
            'action' => (string) $crawler->filter('form[name="content_schedule_change"]')->attr('action'),
            'token' => (string) $crawler->filter('input[name="content_schedule_change[_token]"]')->attr('value'),
            'expectedAt' => (string) $crawler->filter('input[name="content_schedule_change[expectedAt]"]')->attr('value'),
        ];
    }

    /**
     * @param array{action: string, token: string, expectedAt: string} $form
     */
    private function submitChange(
        KernelBrowser $client,
        array $form,
        \DateTimeImmutable $scheduledAt,
        string $reason,
        string $token,
        ?string $expectedAt = null,
    ): void {
        $client->request('POST', $form['action'], [
            'content_schedule_change' => [
                'scheduledAt' => $scheduledAt->format('Y-m-d\TH:i'),
                'reason' => $reason,
                'expectedAt' => $expectedAt ?? $form['expectedAt'],
                '_token' => $token,
            ],
        ]);
    }

    private function submitRejectedChange(
        KernelBrowser $client,
        int $entryId,
        string $kind,
        string $month,
        \DateTimeImmutable $scheduledAt,
        string $reason,
    ): void {
        $form = $this->formState($client, $entryId, $kind, $month);
        $this->submitChange($client, $form, $scheduledAt, $reason, $form['token']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    /** @return list<int> */
    private function eventIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('tr[data-schedule-entry]') as $row) {
            $ids[] = (int) $row->getAttribute('data-schedule-entry');
        }

        return $ids;
    }

    private function entryId(ContentEntry $entry): int
    {
        $id = $entry->getId();
        if ($id === null) {
            throw new \LogicException('A synthetic content entry was not persisted.');
        }

        return $id;
    }

    private function assertSameMinute(\DateTimeImmutable $expected, ?\DateTimeImmutable $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame($expected->format('Y-m-d H:i'), $actual->format('Y-m-d H:i'));
    }

    private function assertChangeEvidence(
        EntityManagerInterface $em,
        ContentEntry $entry,
        string $kind,
        \DateTimeImmutable $previousAt,
        string $reason,
    ): void {
        $revision = $em->getRepository(ContentRevision::class)->findOneBy(['entry' => $entry]);
        self::assertInstanceOf(ContentRevision::class, $revision);
        self::assertSame($entry->getStatus(), $revision->getStatus());

        $column = $kind === 'publication' ? 'scheduled_at' : 'scheduled_unpublish_at';
        $snapshot = $em->getConnection()->fetchOne(
            sprintf('SELECT %s FROM content_revision WHERE id = ?', $column),
            [$revision->getId()],
        );
        self::assertIsString($snapshot);
        self::assertSame($previousAt->format('Y-m-d H:i'), (new \DateTimeImmutable($snapshot))->format('Y-m-d H:i'));

        $logs = $em->getRepository(AuditLog::class)->findBy([
            'action' => 'content.schedule.changed',
            'subjectId' => $entry->getId(),
        ]);
        self::assertCount(1, $logs);
        $log = $logs[0];
        self::assertInstanceOf(AuditLog::class, $log);
        $context = $log->getContext();
        self::assertSame($kind, $context['event'] ?? null);
        self::assertSame($reason, $context['reason'] ?? null);
        self::assertSame($previousAt->format(DATE_ATOM), $context['previousAt'] ?? null);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();

        return $em;
    }
}
