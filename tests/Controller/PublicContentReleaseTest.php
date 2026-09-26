<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentReleaseTest extends WebTestCase
{
    public function testNewsLinksToNewestFirstPublicReleaseArchiveWithTwentyRowsPerPage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($entityManager, $suffix);
            $publishedAt = new \DateTimeImmutable();

            for ($number = 1; $number <= 21; ++$number) {
                $entry = $this->entry($user, $suffix.'-'.$number);
                $release = (new ContentRelease())
                    ->setName(sprintf('Public release %s %02d', $suffix, $number))
                    ->setDescription('Public release description.')
                    ->setCreatedBy($user)
                    ->addEntry($entry);
                $release->publish($publishedAt);

                $entityManager->persist($entry);
                $entityManager->persist($release);
            }
            $entityManager->flush();

            $client->request('GET', '/news');
            self::assertResponseIsSuccessful();
            self::assertSame(1, $client->getCrawler()->filter('a[href="/releases"]')->count());

            $crawler = $client->request('GET', '/releases');
            self::assertResponseIsSuccessful();
            $this->assertNoStore($client);
            self::assertSame(20, $crawler->filter('main .news-grid article')->count());
            self::assertSame(
                sprintf('Public release %s 21', $suffix),
                trim($crawler->filter('main .news-grid article h2')->first()->text()),
            );
            self::assertStringNotContainsString('Public release '.$suffix.' 01', $client->getResponse()->getContent() ?: '');

            $crawler = $client->request('GET', '/releases?page=2');
            self::assertResponseIsSuccessful();
            self::assertSame(
                sprintf('Public release %s 01', $suffix),
                trim($crawler->filter('main .news-grid article h2')->first()->text()),
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testReleaseDetailShowsOnlyCurrentPublicEntriesAndPaginatesThem(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($entityManager, $suffix);
            $entries = [];
            for ($number = 1; $number <= 21; ++$number) {
                $entries[] = $this->entry($user, 'visible-'.$suffix.'-'.$number);
            }

            $unlisted = $this->entry($user, 'unlisted-'.$suffix);
            $trashed = $this->entry($user, 'trashed-'.$suffix);
            $draft = $this->entry($user, 'draft-'.$suffix);
            $scheduled = $this->entry($user, 'scheduled-'.$suffix);
            $archived = $this->entry($user, 'archived-'.$suffix);
            $future = $this->entry($user, 'future-'.$suffix);
            $trashed->trash();

            $release = (new ContentRelease())
                ->setName('Visibility release '.$suffix)
                ->setDescription('Public release summary.')
                ->setCreatedBy($user);
            foreach ([...$entries, $unlisted, $trashed, $draft, $scheduled, $archived, $future] as $entry) {
                $release->addEntry($entry);
                $entityManager->persist($entry);
            }
            $release->publish(new \DateTimeImmutable('-5 minutes'));

            $unlisted->setUnlisted(true);
            $draft->setStatus(ContentEntry::STATUS_DRAFT)->setPublishedAt(null);
            $scheduled->setStatus(ContentEntry::STATUS_SCHEDULED)->setScheduledAt(new \DateTimeImmutable('+1 day'))->setPublishedAt(null);
            $archived->setStatus(ContentEntry::STATUS_ARCHIVED)->setPublishedAt(null);
            $future->setPublishedAt(new \DateTimeImmutable('+1 day'));

            $entityManager->persist($release);
            $entityManager->flush();
            $releaseId = $release->getId();
            self::assertNotNull($releaseId);

            $crawler = $client->request('GET', '/releases/'.$releaseId);
            self::assertResponseIsSuccessful();
            $this->assertNoStore($client);
            self::assertStringContainsString('21 öffentliche Inhalte', $client->getResponse()->getContent() ?: '');
            self::assertSame(20, $crawler->filter('main .news-grid article')->count());
            self::assertSame(
                'Release entry visible-'.$suffix.'-21',
                trim($crawler->filter('main .news-grid article h3')->first()->text()),
            );

            $body = $client->getResponse()->getContent() ?: '';
            foreach (['unlisted', 'trashed', 'draft', 'scheduled', 'archived', 'future'] as $hidden) {
                self::assertStringNotContainsString($hidden.'-'.$suffix, $body);
            }

            $crawler = $client->request('GET', '/releases/'.$releaseId.'?page=2');
            self::assertResponseIsSuccessful();
            self::assertSame(
                'Release entry visible-'.$suffix.'-1',
                trim($crawler->filter('main .news-grid article h3')->first()->text()),
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testUnpublishedReleasesAreNotPublicAndPaginationRejectsInvalidInput(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($entityManager, $suffix);

            $draft = (new ContentRelease())
                ->setName('Draft release '.$suffix)
                ->setCreatedBy($user);
            $scheduled = (new ContentRelease())
                ->setName('Scheduled release '.$suffix)
                ->setCreatedBy($user)
                ->setStatus(ContentRelease::STATUS_SCHEDULED)
                ->setScheduledAt(new \DateTimeImmutable('+1 day'));
            $cancelled = (new ContentRelease())
                ->setName('Cancelled release '.$suffix)
                ->setCreatedBy($user)
                ->setStatus(ContentRelease::STATUS_CANCELLED);
            $futureEntry = $this->entry($user, 'future-release-'.$suffix);
            $future = (new ContentRelease())
                ->setName('Future release '.$suffix)
                ->setCreatedBy($user)
                ->addEntry($futureEntry);
            $future->publish(new \DateTimeImmutable('+1 day'));

            $publicEntry = $this->entry($user, 'current-release-'.$suffix);
            $public = (new ContentRelease())
                ->setName('Current release '.$suffix)
                ->setCreatedBy($user)
                ->addEntry($publicEntry);
            $public->publish(new \DateTimeImmutable('-5 minutes'));

            foreach ([$draft, $scheduled, $cancelled, $future] as $release) {
                $entityManager->persist($release);
            }
            foreach ([$futureEntry, $publicEntry, $future, $public] as $record) {
                $entityManager->persist($record);
            }
            $entityManager->flush();

            foreach ([$draft, $scheduled, $cancelled, $future] as $release) {
                self::assertNotNull($release->getId());
                $client->request('GET', '/releases/'.$release->getId());
                self::assertResponseStatusCodeSame(404);
            }

            self::assertNotNull($public->getId());
            foreach (['page=0', 'page=-1', 'page=10001', 'page=abc', 'page[]=1'] as $query) {
                $client->request('GET', '/releases?'.$query);
                self::assertResponseStatusCodeSame(400);
            }
            $client->request('GET', '/releases/'.$public->getId().'?page[]=2');
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/releases/'.$public->getId().'?page=2');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/releases/999999999999999999999999999999');
            self::assertResponseStatusCodeSame(404);

            $crawler = $client->request('GET', '/releases');
            self::assertResponseIsSuccessful();
            $body = $client->getResponse()->getContent() ?: '';
            foreach (['Draft', 'Scheduled', 'Cancelled', 'Future'] as $hidden) {
                self::assertStringNotContainsString($hidden.' release '.$suffix, $body);
            }
            self::assertStringContainsString('Current release '.$suffix, $body);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function user(EntityManagerInterface $entityManager, string $suffix): User
    {
        $user = (new User())
            ->setEmail('public-release-'.$suffix.'@example.test')
            ->setDisplayName('Public release test');
        $entityManager->persist($user);

        return $user;
    }

    private function entry(User $author, string $slug): ContentEntry
    {
        return (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Release entry '.$slug)
            ->setSlug('release-entry-'.$slug)
            ->setExcerpt('Release summary '.$slug)
            ->setBody('Public release test body.')
            ->setStatus(ContentEntry::STATUS_DRAFT);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function assertNoStore(KernelBrowser $client): void
    {
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }
}
