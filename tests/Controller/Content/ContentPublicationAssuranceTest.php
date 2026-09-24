<?php

declare(strict_types=1);

namespace App\Tests\Controller\Content;

use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\Repository\ContentRevisionRepository;
use App\Security\CmsPermission;
use App\Service\ContentRevisionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentPublicationAssuranceTest extends WebTestCase
{
    public function testContentAdministrationAndReleasesRequireContentPermission(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, []));

        $client->request('GET', '/admin/content');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/content/releases');
        self::assertResponseStatusCodeSame(403);
    }

    public function testBrowserPublishJourneyCreatesPublicContentAndPreservesOldSlugRedirect(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($admin);

        $suffix = bin2hex(random_bytes(4));
        $oldSlug = 'publication-'.$suffix;
        $newSlug = 'publication-renamed-'.$suffix;

        $crawler = $client->request('GET', '/admin/content/new');
        $form = $crawler->selectButton('Speichern')->form([
            'content_entry[type]' => ContentEntry::TYPE_NEWS,
            'content_entry[title]' => 'Published '.$suffix,
            'content_entry[slug]' => $oldSlug,
            'content_entry[body]' => 'Published body '.$suffix,
            'content_entry[status]' => ContentEntry::STATUS_PUBLISHED,
        ]);
        $client->submit($form);

        self::assertTrue($client->getResponse()->isRedirect());
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/admin/content/(\\d+)/edit$#', $location);
        preg_match('#^/admin/content/(\\d+)/edit$#', $location, $match);
        $entryId = (int) $match[1];

        $this->em($client)->clear();
        $stored = $this->em($client)->find(ContentEntry::class, $entryId);
        self::assertInstanceOf(ContentEntry::class, $stored);
        self::assertSame(ContentEntry::STATUS_PUBLISHED, $stored->getStatus());
        self::assertNotNull($stored->getPublishedAt());

        $client->request('GET', '/news/'.$oldSlug);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Published '.$suffix);

        $crawler = $client->request('GET', '/admin/content/'.$entryId.'/edit');
        $form = $crawler->selectButton('Speichern')->form([
            'content_entry[slug]' => $newSlug,
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/admin/content/'.$entryId.'/edit');

        $client->request('GET', '/news/'.$oldSlug);
        self::assertResponseStatusCodeSame(301);
        self::assertSame('/news/'.$newSlug, $client->getResponse()->headers->get('Location'));

        $client->request('GET', '/news/'.$newSlug);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Published '.$suffix);
    }

    public function testSchedulingRejectsInvalidWindowAndHonorsExactDueBoundaries(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($admin);

        $suffix = bin2hex(random_bytes(4));
        $start = (new \DateTimeImmutable('+2 days'))->setTime(12, 0);
        $invalidEnd = $start->modify('-1 hour');

        $crawler = $client->request('GET', '/admin/content/new');
        $form = $crawler->selectButton('Speichern')->form([
            'content_entry[type]' => ContentEntry::TYPE_NEWS,
            'content_entry[title]' => 'Invalid schedule '.$suffix,
            'content_entry[slug]' => 'invalid-schedule-'.$suffix,
            'content_entry[body]' => 'Invalid scheduling window.',
            'content_entry[status]' => ContentEntry::STATUS_SCHEDULED,
            'content_entry[scheduledAt]' => $start->format('Y-m-d\\TH:i'),
            'content_entry[scheduledUnpublishAt]' => $invalidEnd->format('Y-m-d\\TH:i'),
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Das geplante Ende muss nach der geplanten Veröffentlichung liegen.');
        self::assertSame(0, $this->em($client)->getRepository(ContentEntry::class)->count([
            'slug' => 'invalid-schedule-'.$suffix,
        ]));

        $end = $start->modify('+3 hours');
        $crawler = $client->request('GET', '/admin/content/new');
        $form = $crawler->selectButton('Speichern')->form([
            'content_entry[type]' => ContentEntry::TYPE_NEWS,
            'content_entry[title]' => 'Scheduled '.$suffix,
            'content_entry[slug]' => 'scheduled-'.$suffix,
            'content_entry[body]' => 'Scheduled publication body.',
            'content_entry[status]' => ContentEntry::STATUS_SCHEDULED,
            'content_entry[scheduledAt]' => $start->format('Y-m-d\\TH:i'),
            'content_entry[scheduledUnpublishAt]' => $end->format('Y-m-d\\TH:i'),
        ]);
        $client->submit($form);

        self::assertTrue($client->getResponse()->isRedirect());
        $entry = $this->em($client)->getRepository(ContentEntry::class)->findOneBy(['slug' => 'scheduled-'.$suffix]);
        self::assertInstanceOf(ContentEntry::class, $entry);
        self::assertSame(ContentEntry::STATUS_SCHEDULED, $entry->getStatus());
        self::assertNull($entry->getPublishedAt());
        self::assertNotNull($entry->getScheduledAt());
        self::assertNotNull($entry->getScheduledUnpublishAt());

        $scheduledAt = $entry->getScheduledAt();
        $scheduledEnd = $entry->getScheduledUnpublishAt();
        self::assertFalse($entry->publishIfDue($scheduledAt->modify('-1 second')));
        self::assertTrue($entry->publishIfDue($scheduledAt));
        self::assertSame(ContentEntry::STATUS_PUBLISHED, $entry->getStatus());
        self::assertSame($scheduledAt, $entry->getPublishedAt());

        self::assertFalse($entry->unpublishIfDue($scheduledEnd->modify('-1 second')));
        self::assertTrue($entry->unpublishIfDue($scheduledEnd));
        self::assertSame(ContentEntry::STATUS_ARCHIVED, $entry->getStatus());
    }

    public function testReleasePublishRequiresCsrfAndBecomesFinalAfterSuccessfulPublication(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $entry = $this->entry($client, $admin, ContentEntry::STATUS_DRAFT);
        $release = (new ContentRelease())
            ->setName('Release '.bin2hex(random_bytes(4)))
            ->setCreatedBy($admin)
            ->addEntry($entry);
        $this->em($client)->persist($release);
        $this->em($client)->flush();
        $releaseId = $release->getId();
        $entryId = $entry->getId();
        self::assertNotNull($releaseId);
        self::assertNotNull($entryId);
        $client->loginUser($admin);

        $client->request('POST', '/admin/content/releases/'.$releaseId.'/publish');
        self::assertResponseStatusCodeSame(403);

        $this->em($client)->clear();
        $storedRelease = $this->em($client)->find(ContentRelease::class, $releaseId);
        $storedEntry = $this->em($client)->find(ContentEntry::class, $entryId);
        self::assertInstanceOf(ContentRelease::class, $storedRelease);
        self::assertInstanceOf(ContentEntry::class, $storedEntry);
        self::assertSame(ContentRelease::STATUS_DRAFT, $storedRelease->getStatus());
        self::assertSame(ContentEntry::STATUS_DRAFT, $storedEntry->getStatus());

        $crawler = $client->request('GET', '/admin/content/releases');
        $token = $crawler
            ->filter('form[action="/admin/content/releases/'.$releaseId.'/publish"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/admin/content/releases/'.$releaseId.'/publish', ['_token' => $token]);
        self::assertResponseRedirects('/admin/content/releases');

        $this->em($client)->clear();
        $storedRelease = $this->em($client)->find(ContentRelease::class, $releaseId);
        $storedEntry = $this->em($client)->find(ContentEntry::class, $entryId);
        self::assertInstanceOf(ContentRelease::class, $storedRelease);
        self::assertInstanceOf(ContentEntry::class, $storedEntry);
        self::assertSame(ContentRelease::STATUS_PUBLISHED, $storedRelease->getStatus());
        self::assertNotNull($storedRelease->getPublishedAt());
        self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedEntry->getStatus());
        self::assertNotNull($storedEntry->getPublishedAt());

        $client->request('POST', '/admin/content/releases/'.$releaseId.'/publish', ['_token' => $token]);
        self::assertResponseStatusCodeSame(409);

        $client->request('GET', '/admin/content/releases/'.$releaseId.'/edit');
        self::assertResponseRedirects('/admin/content/releases');
    }

    public function testRevisionRestoreKeepsSnapshotsImmutableAndRestoresAsDraft(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $entry = $this->entry($client, $admin, ContentEntry::STATUS_DRAFT)
            ->setTitle('Original title')
            ->setBody('Original body');
        $this->em($client)->flush();

        $manager = $client->getContainer()->get(ContentRevisionManager::class);
        $revisionOne = $manager->capture($entry, $admin);
        $this->em($client)->flush();
        $revisionOneId = $revisionOne->getId();
        self::assertNotNull($revisionOneId);

        $entry->setTitle('Current title')->setBody('Current body')->setStatus(ContentEntry::STATUS_REVIEW);
        $manager->capture($entry, $admin);
        $this->em($client)->flush();
        $entryId = $entry->getId();
        self::assertNotNull($entryId);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/content/'.$entryId.'/history');
        $token = $crawler
            ->filter('form[action="/admin/content/'.$entryId.'/revisions/'.$revisionOneId.'/restore"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/admin/content/'.$entryId.'/revisions/'.$revisionOneId.'/restore', ['_token' => $token]);
        self::assertResponseRedirects('/admin/content/'.$entryId.'/edit');

        $this->em($client)->clear();
        $storedEntry = $this->em($client)->find(ContentEntry::class, $entryId);
        $storedRevisionOne = $this->em($client)->find(ContentRevision::class, $revisionOneId);
        self::assertInstanceOf(ContentEntry::class, $storedEntry);
        self::assertInstanceOf(ContentRevision::class, $storedRevisionOne);
        self::assertSame('Original title', $storedEntry->getTitle());
        self::assertSame('Original body', $storedEntry->getBody());
        self::assertSame(ContentEntry::STATUS_DRAFT, $storedEntry->getStatus());
        self::assertNull($storedEntry->getPublishedAt());

        self::assertSame('Original title', $storedRevisionOne->getTitle());
        self::assertSame('Original body', $storedRevisionOne->getBody());

        $revisions = $client->getContainer()->get(ContentRevisionRepository::class)->forEntry($storedEntry);
        self::assertCount(3, $revisions);
        self::assertSame('Current title', $revisions[0]->getTitle());
        self::assertSame(ContentEntry::STATUS_REVIEW, $revisions[0]->getStatus());
    }

    public function testRestoreRejectsRevisionOwnedByAnotherEntry(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $first = $this->entry($client, $admin, ContentEntry::STATUS_DRAFT);
        $second = $this->entry($client, $admin, ContentEntry::STATUS_DRAFT);
        $foreign = $client->getContainer()->get(ContentRevisionManager::class)->capture($second, $admin);
        $this->em($client)->flush();
        self::assertNotNull($first->getId());
        self::assertNotNull($foreign->getId());
        $client->loginUser($admin);

        $client->request(
            'POST',
            '/admin/content/'.$first->getId().'/revisions/'.$foreign->getId().'/restore',
            ['_token' => 'irrelevant-because-ownership-check-runs-first'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-assurance-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content assurance')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(KernelBrowser $client, User $author, string $status): ContentEntry
    {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Entry '.$suffix)
            ->setSlug('entry-'.$suffix)
            ->setBody('Body '.$suffix)
            ->setStatus($status);
        $entry->synchronizePublication();
        $this->em($client)->persist($entry);
        $this->em($client)->flush();

        return $entry;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
