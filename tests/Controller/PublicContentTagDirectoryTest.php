<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ContentTag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicContentTagDirectoryTest extends WebTestCase
{
    public function testAnonymousDirectoryListsTagsInStableOrderWithExistingArchiveLinks(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $tags = [
            (new ContentTag())
                ->setName('Alpha '.$suffix)
                ->setSlug('directory-alpha-first-'.$suffix)
                ->setDescription('First tag description '.$suffix),
            (new ContentTag())
                ->setName('Alpha '.$suffix)
                ->setSlug('directory-alpha-second-'.$suffix),
            (new ContentTag())
                ->setName('Beta '.$suffix)
                ->setSlug('directory-beta-'.$suffix)
                ->setDescription('Beta tag description '.$suffix),
            (new ContentTag())
                ->setName('Zeta '.$suffix)
                ->setSlug('directory-zeta-'.$suffix),
        ];
        $entityManager = $this->em($client);
        foreach ($tags as $tag) {
            $entityManager->persist($tag);
        }
        $entityManager->flush();
        $tagSlugs = array_map(static fn (ContentTag $tag): string => $tag->getSlug(), $tags);

        try {
            $crawler = $client->request('GET', '/news/tags');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Tags');
            self::assertSelectorTextContains('body', 'First tag description '.$suffix);
            self::assertSelectorTextContains('body', 'Beta tag description '.$suffix);
            self::assertCount(2, $crawler->filter('.tag-directory-item .tag-description'));

            /** @var list<array{name: string, href: string|null}> $items */
            $items = $crawler->filter('.tag-directory-item')->each(
                static fn (Crawler $card): array => [
                    'name' => trim($card->filter('h2')->text()),
                    'href' => $card->filter('h2 a')->attr('href'),
                ],
            );
            $fixtureLinks = [];
            foreach ($items as $item) {
                if (str_contains($item['name'], $suffix) && $item['href'] !== null) {
                    $fixtureLinks[] = $item['href'];
                }
            }

            self::assertSame(array_map(static fn (ContentTag $tag): string => '/news/tag/'.$tag->getSlug(), $tags), $fixtureLinks);
            self::assertSelectorNotExists('.tag-directory-item form, .tag-directory-item button');

            $client->request('POST', '/news/tags');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->removeTags($client, $tagSlugs);
        }
    }

    public function testDirectoryShowsUsefulEmptyStateWhenNoTagsExist(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->em($client);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('DELETE FROM content_entry_tag');
            $connection->executeStatement('DELETE FROM content_tag');
            $entityManager->clear();

            $client->request('GET', '/news/tags');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Tags');
            self::assertSelectorTextContains('body', 'Noch keine Tags vorhanden.');
            self::assertSelectorNotExists('.tag-directory-item');
            self::assertSelectorNotExists('form');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testDisabledContentModuleReturnsNotFoundWithoutTagContents(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $tag = (new ContentTag())
            ->setName('Hidden tag directory '.$suffix)
            ->setSlug('hidden-tag-directory-'.$suffix);
        $entityManager = $this->em($client);
        $entityManager->persist($tag);
        $entityManager->flush();
        $tagSlug = $tag->getSlug();

        $state = $entityManager->getRepository(CmsModuleState::class)->find('content');
        $previousEnabled = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())
                ->setModuleKey('content')
                ->updateVersion('1.0.0');
            $entityManager->persist($state);
        }
        $state->setEnabled(false);
        $entityManager->flush();

        try {
            $client->request('GET', '/news/tags');

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Hidden tag directory '.$suffix, (string) $client->getResponse()->getContent());
        } finally {
            $this->restoreContentModule($client, $previousEnabled);
            $this->removeTags($client, [$tagSlug]);
        }
    }

    /** @param list<string> $slugs */
    private function removeTags(KernelBrowser $client, array $slugs): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        foreach ($slugs as $slug) {
            $tag = $entityManager->getRepository(ContentTag::class)->findOneBy(['slug' => $slug]);
            if ($tag instanceof ContentTag) {
                $entityManager->remove($tag);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function restoreContentModule(KernelBrowser $client, ?bool $previousEnabled): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        $state = $entityManager->getRepository(CmsModuleState::class)->find('content');
        if ($previousEnabled === null) {
            if ($state !== null) {
                $entityManager->remove($state);
            }
        } elseif ($state !== null) {
            $state->setEnabled($previousEnabled);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
