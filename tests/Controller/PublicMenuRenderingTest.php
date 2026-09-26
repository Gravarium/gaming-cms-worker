<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\MenuItem;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicMenuRenderingTest extends WebTestCase
{
    public function testPublicNavigationRendersOrderedLinksAndHidesUnavailablePages(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $entityManager = $this->entityManager($client);
        $author = $this->user($client, $suffix);
        $now = new \DateTimeImmutable();

        $visiblePage = $this->page(
            $author,
            'menu-visible-'.$suffix,
            ContentEntry::TYPE_PAGE,
            ContentEntry::STATUS_PUBLISHED,
            false,
            $now->modify('-1 day'),
            $now->modify('+1 day'),
        );
        $draftPage = $this->page($author, 'menu-draft-'.$suffix, status: ContentEntry::STATUS_DRAFT);
        $unlistedPage = $this->page($author, 'menu-unlisted-'.$suffix, unlisted: true);
        $expiredPage = $this->page($author, 'menu-expired-'.$suffix, unpublishAt: $now->modify('-1 minute'));
        $futurePage = $this->page($author, 'menu-future-'.$suffix, publishedAt: $now->modify('+1 day'));
        $newsEntry = $this->page($author, 'menu-news-'.$suffix, type: ContentEntry::TYPE_NEWS);

        foreach ([$visiblePage, $draftPage, $unlistedPage, $expiredPage, $futurePage, $newsEntry] as $entry) {
            $entityManager->persist($entry);
        }

        $externalLabel = 'External '.$suffix;
        $externalUrl = 'https://example.com/community-'.$suffix;
        $externalItem = (new MenuItem())
            ->setLabel($externalLabel)
            ->setUrl($externalUrl)
            ->setPosition(20)
            ->setOpenNewWindow(true);
        $pageLabel = 'Page '.$suffix;
        $pageItem = (new MenuItem())
            ->setLabel($pageLabel)
            ->setPage($visiblePage)
            ->setPosition(30);
        $disabledItem = (new MenuItem())
            ->setLabel('Disabled '.$suffix)
            ->setUrl('https://example.com/disabled-'.$suffix)
            ->setPosition(10)
            ->setEnabled(false);

        $hiddenItems = [];
        foreach ([$draftPage, $unlistedPage, $expiredPage, $futurePage, $newsEntry] as $index => $entry) {
            $item = (new MenuItem())
                ->setLabel('Hidden '.$suffix.' '.$index)
                ->setPage($entry)
                ->setPosition(40 + $index);
            $hiddenItems[] = $item;
        }

        foreach ([$externalItem, $pageItem, $disabledItem, ...$hiddenItems] as $item) {
            $entityManager->persist($item);
        }
        $entityManager->flush();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $navigation = $crawler->filter('nav[aria-label="Hauptnavigation"]');
        self::assertSame(1, $navigation->count());

        $links = $navigation->filter('a');
        $hrefs = $links->each(static fn (Crawler $link): ?string => $link->attr('href'));
        $labels = $links->each(static fn (Crawler $link): string => trim($link->text()));

        $externalLink = $navigation->filter('a[href="'.$externalUrl.'"]');
        self::assertSame(1, $externalLink->count());
        self::assertSame('_blank', $externalLink->attr('target'));
        self::assertSame('noopener noreferrer', $externalLink->attr('rel'));
        self::assertContains('/page/'.$visiblePage->getSlug(), $hrefs);
        self::assertNotContains('https://example.com/disabled-'.$suffix, $hrefs);

        foreach ([$draftPage, $unlistedPage, $expiredPage, $futurePage, $newsEntry] as $entry) {
            self::assertNotContains('/page/'.$entry->getSlug(), $hrefs);
        }

        $externalIndex = array_search($externalLabel, $labels, true);
        $pageIndex = array_search($pageLabel, $labels, true);
        self::assertTrue(is_int($externalIndex) && is_int($pageIndex) && $externalIndex < $pageIndex);
    }

    public function testDisablingContentHidesPageLinksButKeepsExternalLinks(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $entityManager = $this->entityManager($client);
        $author = $this->user($client, $suffix);
        $page = $this->page($author, 'menu-disabled-module-'.$suffix);
        $externalUrl = 'https://example.com/external-'.$suffix;

        $entityManager->persist($page);
        $entityManager->persist((new MenuItem())->setLabel('Page '.$suffix)->setPage($page)->setPosition(10));
        $entityManager->persist((new MenuItem())->setLabel('External '.$suffix)->setUrl($externalUrl)->setPosition(20));
        $entityManager->flush();

        $existingState = $entityManager->find(CmsModuleState::class, 'content');
        $previousEnabled = $existingState?->isEnabled();
        $state = $existingState ?? (new CmsModuleState())->setModuleKey('content')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $crawler = $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            $navigation = $crawler->filter('nav[aria-label="Hauptnavigation"]');
            self::assertSame(1, $navigation->count());
            self::assertSame(1, $navigation->filter('a[href="'.$externalUrl.'"]')->count());
            self::assertSame(0, $navigation->filter('a[href="/page/'.$page->getSlug().'"]')->count());
        } finally {
            $entityManager = $this->entityManager($client);
            $currentState = $entityManager->find(CmsModuleState::class, 'content');
            if ($previousEnabled === null) {
                if ($currentState instanceof CmsModuleState) {
                    $entityManager->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                $currentState->setEnabled($previousEnabled);
            }
            $entityManager->flush();
        }
    }

    public function testAdminMenuReportsHiddenTargetsWithoutRenderingPublicNavigation(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $entityManager = $this->entityManager($client);
        $admin = $this->user($client, $suffix, [CmsPermission::CONTENT]);
        $hiddenPage = $this->page($admin, 'menu-admin-hidden-'.$suffix, unlisted: true);
        $menuItem = (new MenuItem())
            ->setLabel('Hidden target '.$suffix)
            ->setPage($hiddenPage)
            ->setPosition(10);
        $entityManager->persist($hiddenPage);
        $entityManager->persist($menuItem);
        $entityManager->flush();

        $client->loginUser($admin);
        $client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $client->getCrawler()->filter('nav[aria-label="Hauptnavigation"]')->count());
        self::assertSelectorTextContains('body', 'Ziel ist nicht öffentlich verfügbar.');
        self::assertSelectorTextContains('body', 'Hidden target '.$suffix);
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, string $suffix, array $permissions = []): User
    {
        $user = (new User())
            ->setEmail('public-menu-'.$suffix.'@example.test')
            ->setDisplayName('Public menu test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->entityManager($client)->persist($user);

        return $user;
    }

    private function page(
        User $author,
        string $slug,
        string $type = ContentEntry::TYPE_PAGE,
        string $status = ContentEntry::STATUS_PUBLISHED,
        bool $unlisted = false,
        ?\DateTimeImmutable $publishedAt = null,
        ?\DateTimeImmutable $unpublishAt = null,
    ): ContentEntry {
        if ($status === ContentEntry::STATUS_PUBLISHED && $publishedAt === null) {
            $publishedAt = new \DateTimeImmutable('-1 day');
        }

        return (new ContentEntry())
            ->setType($type)
            ->setTitle('Menu page '.$slug)
            ->setSlug($slug)
            ->setBody('Menu visibility test content')
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setScheduledUnpublishAt($unpublishAt)
            ->setUnlisted($unlisted)
            ->setAuthor($author);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
