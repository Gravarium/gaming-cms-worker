<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ContentReviewWorkflowTest extends WebTestCase
{
    public function testInboxFiltersAndPaginatesReviewEntriesAndDashboardLinksToIt(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $entryIds = [];
        $userIds = [$this->requireId($manager->getId())];

        for ($index = 0; $index < 26; ++$index) {
            $entry = $this->entry($client, $manager, ContentEntry::STATUS_REVIEW, 'Review item '.$index);
            $entryIds[] = $this->requireId($entry->getId());
        }
        $draft = $this->entry($client, $manager, ContentEntry::STATUS_DRAFT, 'Must not appear in review');
        $entryIds[] = $this->requireId($draft->getId());
        $client->loginUser($manager);

        try {
            $crawler = $client->request('GET', '/admin/content/review');
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertStringContainsString('Inhalte warten auf eine Entscheidung.', (string) $client->getResponse()->getContent());
            self::assertCount(25, $this->reviewIds($crawler));
            self::assertStringNotContainsString('Must not appear in review', (string) $client->getResponse()->getContent());
            $firstPageIds = $this->reviewIds($crawler);

            $repeat = $client->request('GET', '/admin/content/review');
            self::assertSame($firstPageIds, $this->reviewIds($repeat));

            $secondPage = $client->request('GET', '/admin/content/review?page=2');
            self::assertResponseIsSuccessful();
            $secondPageIds = $this->reviewIds($secondPage);
            self::assertNotEmpty($secondPageIds);
            self::assertContains($entryIds[0], $secondPageIds);
            self::assertStringNotContainsString('Must not appear in review', (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $client->getCrawler()->filter('a[href="/admin/content/review"]'));
        } finally {
            $this->cleanup($client, $entryIds, $userIds);
        }
    }

    public function testReviewDecisionsPublishScheduleOrReturnContentAndRecordAuditAndRevision(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $userId = $this->requireId($manager->getId());
        $publish = $this->entry($client, $manager, ContentEntry::STATUS_REVIEW, 'Publish decision');
        $schedule = $this->entry($client, $manager, ContentEntry::STATUS_REVIEW, 'Schedule decision');
        $changes = $this->entry($client, $manager, ContentEntry::STATUS_REVIEW, 'Changes decision');
        $ids = array_map(fn (ContentEntry $entry): int => $this->requireId($entry->getId()), [$publish, $schedule, $changes]);
        $client->loginUser($manager);

        try {
            $this->submitDecision($client, $this->requireId($publish->getId()), 'publish', 'Approved after editorial review.');
            self::assertTrue($client->getResponse()->isRedirect());
            $published = $this->storedEntry($client, $this->requireId($publish->getId()));
            self::assertSame(ContentEntry::STATUS_PUBLISHED, $published->getStatus());
            self::assertNotNull($published->getPublishedAt());

            $scheduledAt = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
            $this->submitDecision($client, $this->requireId($schedule->getId()), 'schedule', 'Schedule after campaign launch.', $scheduledAt);
            self::assertTrue($client->getResponse()->isRedirect());
            $scheduled = $this->storedEntry($client, $this->requireId($schedule->getId()));
            self::assertSame(ContentEntry::STATUS_SCHEDULED, $scheduled->getStatus());
            self::assertNotNull($scheduled->getScheduledAt());
            self::assertGreaterThan(new \DateTimeImmutable(), $scheduled->getScheduledAt());

            $this->submitDecision($client, $this->requireId($changes->getId()), 'request_changes', 'Add a source for the second claim.');
            self::assertTrue($client->getResponse()->isRedirect());
            $draft = $this->storedEntry($client, $this->requireId($changes->getId()));
            self::assertSame(ContentEntry::STATUS_DRAFT, $draft->getStatus());
            self::assertNull($draft->getPublishedAt());

            $em = $this->entityManager($client);
            foreach ([
                [$publish, 'publish', 'Approved after editorial review.'],
                [$schedule, 'schedule', 'Schedule after campaign launch.'],
                [$changes, 'request_changes', 'Add a source for the second claim.'],
            ] as [$source, $decision, $reason]) {
                $id = $this->requireId($source->getId());
                $stored = $this->storedEntry($client, $id);
                $logs = $em->getRepository(AuditLog::class)->findBy([
                    'action' => 'content.review.decision',
                    'subjectId' => $id,
                ]);
                self::assertCount(1, $logs);
                self::assertSame($decision, $logs[0]->getContext()['decision']);
                self::assertSame($reason, $logs[0]->getContext()['reason']);
                self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $stored]));
            }
        } finally {
            $this->cleanup($client, $ids, [$userId]);
        }
    }

    public function testInvalidCsrfReasonAndScheduleDoNotChangeTheReviewEntry(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $entry = $this->entry($client, $manager, ContentEntry::STATUS_REVIEW, 'Protected review item');
        $entryId = $this->requireId($entry->getId());
        $userId = $this->requireId($manager->getId());
        $client->loginUser($manager);

        try {
            $client->request('POST', '/admin/content/review/'.$entryId.'/decision', [
                'content_review_decision' => [
                    'decision' => 'publish',
                    'reason' => 'This reason is long enough for validation.',
                    '_token' => 'invalid-token',
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(ContentEntry::STATUS_REVIEW, $this->storedEntry($client, $entryId)->getStatus());

            $this->submitDecision($client, $entryId, 'publish', '');
            self::assertResponseStatusCodeSame(422);
            self::assertSame(ContentEntry::STATUS_REVIEW, $this->storedEntry($client, $entryId)->getStatus());

            $past = (new \DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i');
            $this->submitDecision($client, $entryId, 'schedule', 'The publication window was checked.', $past);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(ContentEntry::STATUS_REVIEW, $this->storedEntry($client, $entryId)->getStatus());

            $em = $this->entityManager($client);
            self::assertCount(0, $em->getRepository(AuditLog::class)->findBy([
                'action' => 'content.review.decision',
                'subjectId' => $entryId,
            ]));
            self::assertCount(0, $em->getRepository(ContentRevision::class)->findBy([
                'entry' => $this->storedEntry($client, $entryId),
            ]));
        } finally {
            $this->cleanup($client, [$entryId], [$userId]);
        }
    }

    public function testAnonymousReviewQueueRequestRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content/review');
        self::assertResponseRedirects();
    }

    public function testReviewQueueRequiresContentManagePermission(): void
    {
        $client = static::createClient();
        $visitor = $this->user($client, [CmsPermission::ACCESS]);
        $userId = $this->requireId($visitor->getId());
        $client->loginUser($visitor);

        try {
            $client->request('GET', '/admin/content/review');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($client, [], [$userId]);
        }
    }

    public function testPermissionsModuleGatePrivateHeadersAndStaleDecision(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $entry = $this->entry($client, $manager, ContentEntry::STATUS_REVIEW, 'Private queue marker');
        $entryId = $this->requireId($entry->getId());
        $userIds = [$this->requireId($manager->getId())];

        $client->loginUser($manager);

        try {
            $crawler = $client->request('GET', '/admin/content/review');
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertStringContainsString('Private queue marker', (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin/content/review/'.$entryId.'/decision');
            self::assertResponseStatusCodeSame(405);

            $detailCrawler = $client->request('GET', '/admin/content/review/'.$entryId);
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            $previewLink = $detailCrawler->filter('a[href="/admin/content/'.$entryId.'/preview"]');
            self::assertCount(1, $previewLink);
            $client->click($previewLink->link());
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);

            $previous = $this->setContentEnabled($client, false);
            try {
                $client->request('GET', '/admin/content/review');
                self::assertResponseStatusCodeSame(404);
                self::assertStringNotContainsString('Private queue marker', (string) $client->getResponse()->getContent());
            } finally {
                $this->restoreContentEnabled($client, $previous);
            }

            $crawler = $client->request('GET', '/admin/content/review/'.$entryId);
            $form = $crawler->filter('form[action="/admin/content/review/'.$entryId.'/decision"]')->form([
                'content_review_decision[decision]' => 'publish',
                'content_review_decision[reason]' => 'This review became stale.',
            ]);
            $entryForUpdate = $this->storedEntry($client, $entryId);
            $entryForUpdate->setStatus(ContentEntry::STATUS_DRAFT);
            $entryForUpdate->synchronizePublication();
            $this->entityManager($client)->flush();

            $client->submit($form);
            self::assertTrue($client->getResponse()->isRedirect());
            self::assertSame(ContentEntry::STATUS_DRAFT, $this->storedEntry($client, $entryId)->getStatus());
            self::assertCount(0, $this->entityManager($client)->getRepository(AuditLog::class)->findBy([
                'action' => 'content.review.decision',
                'subjectId' => $entryId,
            ]));
        } finally {
            $this->cleanup($client, [$entryId], $userIds);
        }
    }

    public function testMalformedAndOutOfRangeReviewPagesFailClosed(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($manager);

        $client->request('GET', '/admin/content/review?page%5B%5D=1');
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/admin/content/review?page=10001');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/content/review?page=2');
        self::assertResponseStatusCodeSame(404);

        $this->cleanup($client, [], [$this->requireId($manager->getId())]);
    }

    private function submitDecision(
        KernelBrowser $client,
        int $entryId,
        string $decision,
        string $reason,
        ?string $scheduledAt = null,
    ): void {
        $path = '/admin/content/review/'.$entryId.'/decision';
        $crawler = $client->request('GET', '/admin/content/review/'.$entryId);
        $values = [
            'content_review_decision[decision]' => $decision,
            'content_review_decision[reason]' => $reason,
        ];
        if ($scheduledAt !== null) {
            $values['content_review_decision[scheduledAt]'] = $scheduledAt;
        }
        $form = $crawler->filter('form[action="'.$path.'"]')->form($values);
        $client->submit($form);
    }

    /** @return list<int> */
    private function reviewIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('a[data-review-entry]') as $element) {
            $value = $element->getAttribute('data-review-entry');
            if (is_string($value) && ctype_digit($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    private function entry(KernelBrowser $client, User $author, string $status, string $title): ContentEntry
    {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($title.' '.$suffix)
            ->setSlug('content-review-'.$suffix)
            ->setBody('Synthetic content body '.$suffix)
            ->setStatus($status);
        $entry->synchronizePublication();

        $this->entityManager($client)->persist($entry);
        $this->entityManager($client)->flush();

        return $entry;
    }

    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-review-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content review test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function storedEntry(KernelBrowser $client, int $id): ContentEntry
    {
        $entry = $this->entityManager($client)->find(ContentEntry::class, $id);
        if (!$entry instanceof ContentEntry) {
            throw new LogicException('Content review fixture disappeared.');
        }

        return $entry;
    }

    private function setContentEnabled(KernelBrowser $client, bool $enabled): ?bool
    {
        $em = $this->entityManager($client);
        $state = $em->find(CmsModuleState::class, 'content');
        $previous = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('content');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previous;
    }

    private function restoreContentEnabled(KernelBrowser $client, ?bool $previous): void
    {
        $em = $this->entityManager($client);
        $state = $em->find(CmsModuleState::class, 'content');
        if ($state === null) {
            return;
        }
        if ($previous === null) {
            $em->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $em->flush();
    }

    /** @param list<int> $entryIds
     *  @param list<int> $userIds
     */
    private function cleanup(KernelBrowser $client, array $entryIds, array $userIds): void
    {
        $em = $this->entityManager($client);
        $em->clear();

        foreach ($entryIds as $id) {
            $entry = $em->find(ContentEntry::class, $id);
            if (!$entry instanceof ContentEntry) {
                continue;
            }
            foreach ($em->getRepository(ContentRevision::class)->findBy(['entry' => $entry]) as $revision) {
                $em->remove($revision);
            }
            foreach ($em->getRepository(AuditLog::class)->findBy([
                'subjectType' => ContentEntry::class,
                'subjectId' => $id,
            ]) as $log) {
                $em->remove($log);
            }
            $em->remove($entry);
        }

        foreach ($userIds as $id) {
            $user = $em->find(User::class, $id);
            if ($user instanceof User) {
                $em->remove($user);
            }
        }

        $em->flush();
        $em->clear();
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new LogicException('Content review fixture has no identifier.');
        }

        return $id;
    }

    private function assertPrivateNoStore(KernelBrowser $client): void
    {
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('noindex, nofollow, noarchive', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
