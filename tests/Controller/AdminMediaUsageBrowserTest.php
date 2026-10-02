<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\MediaAsset;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminMediaUsageBrowserTest extends WebTestCase
{
    private ?KernelBrowser $client = null;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $assetIds = [];

    /** @var list<string> */
    private array $layoutContexts = [];

    public function testMediaEditPageLinksToUsageBrowser(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $user = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE]);
        $asset = $this->persistAsset($em);
        $client->loginUser($user);

        $client->request('GET', '/admin/storage/media/'.$asset->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/storage/media/'.$asset->getId().'/usage"]');
    }

    public function testAuthorizedUsagePageShowsExactContentAndLayoutReferencesSafely(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $user = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE, CmsPermission::CONTENT, CmsPermission::SETTINGS]);
        $location = '/uploads/media/content/100%_literal.png';
        $asset = $this->persistAsset($em, $location);
        $news = $this->persistEntry(
            $em,
            $user,
            'usage-exact-news',
            '<img src=x onerror=alert(1)>',
            'Excerpt contains '.$location,
            'Body without the reference',
        );
        $falseMatch = $this->persistEntry(
            $em,
            $user,
            'usage-false-match',
            'Must not appear',
            null,
            'A similar path /uploads/media/content/100xxliteral.png is not the stored location.',
        );
        $imageBlockEntry = $this->persistEntry($em, $user, 'usage-image-block', 'Image block reference', null, 'A text-only fallback.');
        $imageBlockEntry->setEditorDocument($this->mediaDocument((int) $asset->getId()));
        $em->flush();
        $page = $this->persistEntry($em, $user, 'usage-layout-page', 'Layout <em>title</em>', null, 'Page body', ContentEntry::TYPE_PAGE);
        $otherAsset = $this->persistAsset($em, '/uploads/media/content/another-asset.png');
        $otherImageBlockEntry = $this->persistEntry($em, $user, 'usage-other-image-block', 'Unrelated image block', null, 'A text-only fallback.');
        $otherImageBlockEntry->setEditorDocument($this->mediaDocument((int) $otherAsset->getId()));
        $em->flush();
        $this->persistLayout($em, 'page-'.$page->getId(), $asset->getId());
        $otherPage = $this->persistEntry($em, $user, 'usage-other-layout-page', 'Other layout', null, 'Page body', ContentEntry::TYPE_PAGE);
        $this->persistLayout($em, 'page-'.$otherPage->getId(), $otherAsset->getId());
        $client->loginUser($user);

        $client->request('GET', '/admin/storage/media/'.$asset->getId().'/usage');

        self::assertResponseIsSuccessful();
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSelectorExists('a[href="/admin/content/'.$news->getId().'/edit"]');
        self::assertSelectorExists('a[href="/admin/content/'.$imageBlockEntry->getId().'/edit"]');
        self::assertSelectorExists('a[href="/admin/layout/page-'.$page->getId().'"]');
        self::assertSelectorTextContains('[data-usage-kind="content"]', '<img src=x onerror=alert(1)>');
        self::assertSelectorTextContains('[data-usage-kind="content"]', 'Kurztext');
        $imageBlockSelector = '[data-content-entry-id="'.$imageBlockEntry->getId().'"]';
        self::assertSelectorTextContains($imageBlockSelector, 'Image block reference');
        self::assertSelectorTextContains($imageBlockSelector, 'Bildblock');
        self::assertSelectorTextContains('[data-usage-kind="layout"]', 'Layout <em>title</em>');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString($location, $html);
        self::assertStringNotContainsString((string) $falseMatch->getTitle(), $html);
        self::assertStringNotContainsString((string) $otherImageBlockEntry->getTitle(), $html);
        self::assertStringNotContainsString((string) $otherPage->getTitle(), $html);

        $client->request('POST', '/admin/storage/media/'.$asset->getId().'/usage');
        self::assertResponseStatusCodeSame(405);
        self::assertSame($location, $this->em($client)->find(MediaAsset::class, $asset->getId())?->getLocation());
    }

    public function testMediaBlockIsFoundWhenAssetIdIsLastJsonField(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $user = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE, CmsPermission::CONTENT]);
        $asset = $this->persistAsset($em);
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $entry = $this->persistEntry($em, $user, 'usage-last-asset-id', 'Last-field media block', null, 'No location reference');
        $entry->setEditorDocument($this->mediaDocumentWithAssetIdLast($assetId));
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/storage/media/'.$assetId.'/usage');

        self::assertResponseIsSuccessful();
        $entryId = $entry->getId();
        self::assertNotNull($entryId);
        $selector = '[data-content-entry-id="'.$entryId.'"]';
        self::assertSelectorExists($selector);
        self::assertSelectorTextContains($selector, 'Bildblock');
    }

    public function testStorageManagerWithoutContentPermissionGetsNoProtectedTitlesOrLinks(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $storageUser = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE]);
        $contentUser = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $asset = $this->persistAsset($em);
        $entry = $this->persistEntry($em, $contentUser, 'usage-redacted', 'Protected draft title', null, 'Contains '.$asset->getLocation());
        $page = $this->persistEntry($em, $contentUser, 'usage-redacted-layout', 'Protected page title', null, 'Page body', ContentEntry::TYPE_PAGE);
        $this->persistLayout($em, 'page-'.$page->getId(), $asset->getId());
        $client->loginUser($storageUser);

        $client->request('GET', '/admin/storage/media/'.$asset->getId().'/usage');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Protected draft title', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Protected page title', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('a[href="/admin/content/'.$entry->getId().'/edit"]');
        self::assertSelectorNotExists('a[href="/admin/layout/page-'.$page->getId().'"]');
        self::assertSelectorExists('[data-usage-kind="layout"]');
    }

    public function testLayoutEditorLinkRequiresSettingsPermission(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $user = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE, CmsPermission::CONTENT]);
        $asset = $this->persistAsset($em);
        $entry = $this->persistEntry($em, $user, 'usage-no-settings', 'Visible content title', null, 'Uses '.$asset->getLocation());
        $page = $this->persistEntry($em, $user, 'usage-no-settings-page', 'Visible page title', null, 'Page body', ContentEntry::TYPE_PAGE);
        $this->persistLayout($em, 'page-'.$page->getId(), $asset->getId());
        $client->loginUser($user);

        $client->request('GET', '/admin/storage/media/'.$asset->getId().'/usage');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/content/'.$entry->getId().'/edit"]');
        self::assertSelectorNotExists('a[href^="/admin/layout/"]');
        self::assertSelectorTextContains('[data-usage-kind="layout"]', 'Seitenlayout');
        self::assertSelectorTextContains('[data-usage-kind="layout"]', 'Visible page title');
    }

    public function testDisabledMediaModuleReturnsNotFound(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $user = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE]);
        $asset = $this->persistAsset($em);
        $previousState = $this->setModuleEnabled($em, 'media', false);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/storage/media/'.$asset->getId().'/usage');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreModuleState($em, 'media', $previousState);
        }
    }

    public function testDisabledContentModuleHidesContentDetailsAndLinks(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $user = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE, CmsPermission::CONTENT]);
        $asset = $this->persistAsset($em);
        $entry = $this->persistEntry($em, $user, 'usage-content-disabled', 'Disabled content title', null, 'Uses '.$asset->getLocation());
        $previousState = $this->setModuleEnabled($em, 'content', false);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/storage/media/'.$asset->getId().'/usage');

            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Disabled content title', (string) $client->getResponse()->getContent());
            self::assertSelectorNotExists('a[href="/admin/content/'.$entry->getId().'/edit"]');
        } finally {
            $this->restoreModuleState($em, 'content', $previousState);
        }
    }

    public function testUsagePageRequiresStoragePermission(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $user = $this->persistUser($em, [CmsPermission::ACCESS]);
        $asset = $this->persistAsset($em);
        $client->loginUser($user);

        $client->request('GET', '/admin/storage/media/'.$asset->getId().'/usage');

        self::assertResponseStatusCodeSame(403);

    }

    public function testUnknownAssetReturnsNotFound(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $storageUser = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE]);
        $client->loginUser($storageUser);

        $client->request('GET', '/admin/storage/media/999999999/usage');
        self::assertResponseStatusCodeSame(404);
    }

    public function testContentAndLayoutResultsAreBounded(): void
    {
        $client = $this->startClient();
        $em = $this->em($client);
        $user = $this->persistUser($em, [CmsPermission::ACCESS, CmsPermission::STORAGE, CmsPermission::CONTENT, CmsPermission::SETTINGS]);
        $asset = $this->persistAsset($em);
        $contentEntries = [];
        $pages = [];
        $suffix = bin2hex(random_bytes(4));

        for ($index = 1; $index <= 51; ++$index) {
            $contentEntries[] = $this->newEntry($user, 'usage-bound-news-'.$suffix.'-'.$index, 'Bound news '.$index, 'Uses '.$asset->getLocation());
            $pages[] = $this->newEntry($user, 'usage-bound-page-'.$suffix.'-'.$index, 'Bound page '.$index, 'Page body', ContentEntry::TYPE_PAGE);
        }
        foreach (array_merge($contentEntries, $pages) as $entry) {
            $em->persist($entry);
        }
        $em->flush();
        foreach (array_merge($contentEntries, $pages) as $entry) {
            $id = $entry->getId();
            self::assertNotNull($id);
            $this->entryIds[] = $id;
        }

        foreach ($pages as $page) {
            $pageId = $page->getId();
            self::assertNotNull($pageId);
            $this->persistLayout($em, 'page-'.$pageId, $asset->getId(), false);
        }
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/storage/media/'.$asset->getId().'/usage');

        self::assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('[data-usage-kind="content"]'));
        self::assertCount(50, $crawler->filter('[data-usage-kind="layout"]'));
        self::assertSelectorTextContains('body', 'Es werden höchstens 50 Inhaltsverwendungen angezeigt.');
        self::assertSelectorTextContains('body', 'Es werden höchstens 50 Layoutverwendungen angezeigt.');
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $em = $this->em($this->client);
            if ($em->isOpen()) {
                foreach ($this->layoutContexts as $context) {
                    $layout = $em->find(PageLayout::class, $context);
                    if ($layout instanceof PageLayout) {
                        $em->remove($layout);
                    }
                }
                foreach ($this->entryIds as $id) {
                    $entry = $em->find(ContentEntry::class, $id);
                    if ($entry instanceof ContentEntry) {
                        $em->remove($entry);
                    }
                }
                foreach ($this->assetIds as $id) {
                    $asset = $em->find(MediaAsset::class, $id);
                    if ($asset instanceof MediaAsset) {
                        $em->remove($asset);
                    }
                }
                foreach ($this->userIds as $id) {
                    $user = $em->find(User::class, $id);
                    if ($user instanceof User) {
                        $em->remove($user);
                    }
                }
                $em->flush();
            }
        }

        parent::tearDown();
    }

    private function startClient(): KernelBrowser
    {
        $this->client = static::createClient();

        return $this->client;
    }

    /** @param list<string> $permissions */
    private function persistUser(EntityManagerInterface $em, array $permissions): User
    {
        $user = (new User())
            ->setEmail('media-usage-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Media usage test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $em->persist($user);
        $em->flush();
        $id = $user->getId();
        self::assertNotNull($id);
        $this->userIds[] = $id;

        return $user;
    }

    private function persistAsset(EntityManagerInterface $em, ?string $location = null): MediaAsset
    {
        $asset = (new MediaAsset())
            ->setModuleKey('content')
            ->setStorageMode('internal')
            ->setLocation($location ?? '/uploads/media/content/'.bin2hex(random_bytes(6)).'-usage.png')
            ->setOriginalName('usage.png')
            ->setTitle('Usage test image')
            ->setMimeType('image/png')
            ->setFileSize(10);
        $em->persist($asset);
        $em->flush();
        $id = $asset->getId();
        self::assertNotNull($id);
        $this->assetIds[] = $id;

        return $asset;
    }

    private function persistEntry(
        EntityManagerInterface $em,
        User $author,
        string $slugPrefix,
        string $title,
        ?string $excerpt,
        string $body,
        string $type = ContentEntry::TYPE_NEWS,
    ): ContentEntry {
        $entry = $this->newEntry($author, $slugPrefix.'-'.bin2hex(random_bytes(4)), $title, $body, $type, $excerpt);
        $em->persist($entry);
        $em->flush();
        $id = $entry->getId();
        self::assertNotNull($id);
        $this->entryIds[] = $id;

        return $entry;
    }

    private function newEntry(
        User $author,
        string $slug,
        string $title,
        string $body,
        string $type = ContentEntry::TYPE_NEWS,
        ?string $excerpt = null,
    ): ContentEntry {
        return (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSlug($slug)
            ->setExcerpt($excerpt)
            ->setBody($body);
    }

    private function persistLayout(EntityManagerInterface $em, string $context, ?int $assetId, bool $flush = true): void
    {
        $widgets = $assetId === null ? [] : [['key' => 'media.image', 'config' => ['imageId' => $assetId]]];
        $layout = new PageLayout($context);
        $layout->replace(['widgets' => $widgets]);
        $em->persist($layout);
        $this->layoutContexts[] = $context;
        if ($flush) {
            $em->flush();
        }
    }

    private function mediaDocumentWithAssetIdLast(int $assetId): string
    {
        return ContentBlockDocument::PREFIX.json_encode(
            ['version' => ContentBlockDocument::VERSION, 'blocks' => [['type' => 'media', 'alt' => '', 'caption' => '', 'assetId' => $assetId]]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function mediaDocument(int $assetId): string
    {
        return ContentBlockDocument::PREFIX.json_encode(
            ['version' => ContentBlockDocument::VERSION, 'blocks' => [['type' => 'media', 'assetId' => $assetId, 'alt' => '', 'caption' => '']]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function setModuleEnabled(EntityManagerInterface $em, string $key, bool $enabled): ?bool
    {
        $state = $em->find(CmsModuleState::class, $key);
        $previousState = $state?->isEnabled();
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previousState;
    }

    private function restoreModuleState(EntityManagerInterface $em, string $key, ?bool $previousState): void
    {
        $state = $em->find(CmsModuleState::class, $key);
        if (!$state instanceof CmsModuleState) {
            return;
        }
        if ($previousState === null) {
            $em->remove($state);
        } else {
            $state->setEnabled($previousState);
        }
        $em->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
