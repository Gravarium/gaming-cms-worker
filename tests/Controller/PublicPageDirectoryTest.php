<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicPageDirectoryTest extends WebTestCase
{
    public function testDirectoryListsOnlyPublishedListedPagesWithDetailLinks(): void
    {
        $client = static::createClient();
        $author = $this->user($client);
        $suffix = bin2hex(random_bytes(5));
        $visible = $this->page($client, $author, 'visible-page-'.$suffix, ContentEntry::STATUS_PUBLISHED, false, new \DateTimeImmutable('-1 hour'));
        $unlisted = $this->page($client, $author, 'unlisted-page-'.$suffix, ContentEntry::STATUS_PUBLISHED, true, new \DateTimeImmutable('-2 hours'));
        $draft = $this->page($client, $author, 'draft-page-'.$suffix, ContentEntry::STATUS_DRAFT, false, null);

        $client->request('GET', '/pages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Seiten');
        self::assertSelectorExists('a[href="/page/'.$visible->getSlug().'"]');
        self::assertStringNotContainsString($unlisted->getTitle(), (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($draft->getTitle(), (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('private page body', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('public', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
    }

    public function testDirectoryPaginatesAndRejectsOutOfRangePages(): void
    {
        $client = static::createClient();
        $author = $this->user($client);
        $suffix = bin2hex(random_bytes(5));
        for ($index = 0; $index < 21; ++$index) {
            $this->page(
                $client,
                $author,
                'paged-'.$suffix.'-'.$index,
                ContentEntry::STATUS_PUBLISHED,
                false,
                new \DateTimeImmutable('-'.($index + 1).' minutes'),
            );
        }

        $client->request('GET', '/pages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination', 'Seite 1 von');
        self::assertSelectorExists('a[href="/pages?page=2"]');

        $client->request('GET', '/pages?page=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination', 'Seite 2 von');

        $client->request('GET', '/pages?page=10000');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDirectoryFailsClosedWhenContentModuleIsDisabled(): void
    {
        $client = static::createClient();
        $entityManager = $this->em($client);
        $state = $entityManager->find(CmsModuleState::class, 'content');
        $created = $state === null;
        $wasEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())->setModuleKey('content');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/pages');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($created) {
                $entityManager->remove($state);
            } else {
                $state->setEnabled($wasEnabled);
            }
            $entityManager->flush();
        }
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('page-directory-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Page directory author')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function page(
        KernelBrowser $client,
        User $author,
        string $slug,
        string $status,
        bool $unlisted,
        ?\DateTimeImmutable $publishedAt,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_PAGE)
            ->setTitle('Page '.$slug)
            ->setSlug($slug)
            ->setSubtitle('A page subtitle')
            ->setExcerpt('A page excerpt')
            ->setBody($unlisted ? 'private page body' : 'public page body')
            ->setStatus($status)
            ->setUnlisted($unlisted);
        if ($publishedAt !== null) {
            $entry->setPublishedAt($publishedAt);
        }
        $this->em($client)->persist($entry);
        $this->em($client)->flush();

        return $entry;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
