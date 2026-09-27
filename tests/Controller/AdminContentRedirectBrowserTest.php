<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminContentRedirectBrowserTest extends WebTestCase
{
    public function testRedirectInventoryRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content/redirects');

        self::assertResponseRedirects('/login');
    }

    public function testRedirectInventoryRequiresContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::ACCESS]);
        $client->loginUser($user);
        $client->request('GET', '/admin/content/redirects');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardLinksToRedirectsOnlyForContentManagersWhenModuleEnabled(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($manager);
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        $contentWasEnabled = $modules->isEnabled('content');

        try {
            if (!$contentWasEnabled) {
                $modules->setEnabled('content', true);
            }

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/admin/content/redirects"]');

            $modules->setEnabled('content', false);
            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/admin/content/redirects"]');
        } finally {
            if ($modules->isEnabled('content') !== $contentWasEnabled) {
                $modules->setEnabled('content', $contentWasEnabled);
            }
        }
    }

    public function testRedirectInventoryFiltersSearchAndPreservesTargetsSafely(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(4));
        $searchNeedle = 'needle-'.$suffix;
        $published = $this->addRedirect(
            $client,
            $user,
            ContentEntry::TYPE_NEWS,
            'Current '.$searchNeedle,
            'old-'.$suffix,
            true,
        );
        $draft = $this->addRedirect(
            $client,
            $user,
            ContentEntry::TYPE_PAGE,
            'Draft '.$suffix,
            'old-draft-'.$suffix,
            false,
        );
        $other = $this->addRedirect(
            $client,
            $user,
            ContentEntry::TYPE_NEWS,
            'Different '.$suffix,
            'find-me-'.$suffix,
            true,
        );
        $this->em($client)->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content/redirects?q='.strtoupper($searchNeedle));
        self::assertResponseIsSuccessful();
        self::assertSame('private, no-store, max-age=0', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame(1, $crawler->filter('[data-redirect-id="'.$published->getId().'"]')->count());
        self::assertSame(0, $crawler->filter('[data-redirect-id="'.$draft->getId().'"]')->count());

        $targetSearch = $client->request('GET', '/admin/content/redirects?q='.rawurlencode('draft '.$suffix).'&type=page');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $targetSearch->filter('[data-redirect-id="'.$draft->getId().'"]')->count());
        self::assertSame(0, $targetSearch->filter('[data-redirect-id="'.$other->getId().'"]')->count());

        $client->request('GET', '/admin/content/redirects?type=page');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', '1 Weiterleitung');
        self::assertSelectorExists('a[href="/admin/content/'.$draft->getEntry()->getId().'/edit"]');
        self::assertSelectorTextContains('body', 'Inaktiv · Ziel nicht veröffentlicht');
        self::assertSelectorNotExists('a[href="/news/old-draft-'.$suffix.'"]');
    }

    public function testInventoryPaginatesAllMatchingRedirectsWithoutGapsOrDuplicates(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $search = 'page-'.$suffix;
        $redirects = [];

        for ($index = 0; $index < 55; ++$index) {
            $type = $index % 2 === 0 ? ContentEntry::TYPE_NEWS : ContentEntry::TYPE_PAGE;
            $redirects[] = $this->addRedirect(
                $client,
                $user,
                $type,
                'Target '.$search.' '.$index,
                'historic-'.$search.'-'.$index,
                true,
            );
        }
        $em->flush();

        $em->clear();
        /** @var list<ContentRedirect> $orderedRedirects */
        $orderedRedirects = $em->getRepository(ContentRedirect::class)->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
        $matchingRedirects = array_values(array_filter(
            $orderedRedirects,
            static fn (ContentRedirect $redirect): bool => str_contains($redirect->getSourceSlug(), $search),
        ));
        $expectedIds = array_map(static fn (ContentRedirect $redirect): string => (string) $redirect->getId(), $matchingRedirects);
        $client->loginUser($user);

        $client->request('GET', '/admin/content/redirects?q='.$search);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', '55 Weiterleitungen');
        $firstPageIds = $this->visibleIds($client);
        self::assertCount(25, $firstPageIds);
        $next = $client->getCrawler()->filter('a[aria-label="Nächste Seite"]');
        self::assertSame(1, $next->count());
        self::assertStringContainsString('q='.$search, (string) $next->attr('href'));
        self::assertStringContainsString('page=2', (string) $next->attr('href'));

        $client->request('GET', '/admin/content/redirects?q='.$search.'&page=2');
        self::assertResponseIsSuccessful();
        $secondPageIds = $this->visibleIds($client);
        self::assertCount(25, $secondPageIds);
        $thirdPageIds = $this->fetchThirdPageIds($client, $search);
        self::assertCount(5, $thirdPageIds);
        $visibleIds = array_merge($firstPageIds, $secondPageIds, $thirdPageIds);
        self::assertSame($expectedIds, $visibleIds);
        self::assertCount(55, array_unique($visibleIds));

        $client->request('GET', '/admin/content/redirects?q='.$search.'&page=9999');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Seite 3 von 3');
        self::assertCount(5, $this->visibleIds($client));
        self::assertSelectorNotExists('a[aria-label="Nächste Seite"]');
    }

    public function testInvalidFiltersReturnPrivateBadRequest(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($user);
        $invalidQueries = [
            '/admin/content/redirects?q%5B%5D=slug',
            '/admin/content/redirects?type%5B%5D=news',
            '/admin/content/redirects?page%5B%5D=1',
            '/admin/content/redirects?q=%FF',
            '/admin/content/redirects?q=bad%00slug',
            '/admin/content/redirects?q='.str_repeat('x', 121),
            '/admin/content/redirects?type=video',
            '/admin/content/redirects?page=0',
            '/admin/content/redirects?page=-1',
            '/admin/content/redirects?page=999999999999999999999',
        ];

        foreach ($invalidQueries as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(400, $url);
            self::assertSame('private, no-store, max-age=0', $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('redirect-browser-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Redirect browser')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function addRedirect(
        KernelBrowser $client,
        User $author,
        string $type,
        string $title,
        string $sourceSlug,
        bool $published,
    ): ContentRedirect {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSlug('target-'.$sourceSlug)
            ->setBody('Redirect target.');
        if ($published) {
            $entry->setStatus(ContentEntry::STATUS_PUBLISHED)->synchronizePublication();
        }

        $redirect = new ContentRedirect($entry, $type, $sourceSlug);
        $this->em($client)->persist($entry);
        $this->em($client)->persist($redirect);

        return $redirect;
    }

    /**
     * @return list<string>
     */
    private function visibleIds(KernelBrowser $client): array
    {
        return $client->getCrawler()->filter('[data-redirect-id]')->each(
            static fn (Crawler $row): string => (string) $row->attr('data-redirect-id'),
        );
    }

    /**
     * @return list<string>
     */
    private function fetchThirdPageIds(KernelBrowser $client, string $search): array
    {
        $client->request('GET', '/admin/content/redirects?q='.$search.'&page=3');
        self::assertResponseIsSuccessful();

        return $this->visibleIds($client);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
