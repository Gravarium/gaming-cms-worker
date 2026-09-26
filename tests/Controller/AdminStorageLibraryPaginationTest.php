<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminStorageLibraryPaginationTest extends WebTestCase
{
    public function testEveryFilteredAssetCanBeReachedAcrossPagesBeyondLegacyLimit(): void
    {
        $client = static::createClient();
        $needle = 'media-library-'.bin2hex(random_bytes(5));
        $assets = $this->persistAssets($client, $needle, 251);

        $pending = $this->asset($needle.'-pending', null);
        $pending->markDeletionPending();
        $this->em($client)->persist($pending);
        $this->em($client)->flush();

        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));
        $seen = [];

        for ($page = 1; $page <= 11; ++$page) {
            $crawler = $client->request('GET', '/admin/storage/library/all?'.http_build_query([
                'q' => $needle,
                'page' => $page,
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '251 Treffer');
            self::assertSelectorTextContains('body', 'Seite '.$page.' von 11');
            $visibleIds = $crawler->filter('.media-card')->each(
                static fn (Crawler $card): int => (int) $card->attr('data-asset-id'),
            );
            self::assertCount($page === 11 ? 11 : 24, $visibleIds);

            foreach ($visibleIds as $id) {
                self::assertArrayNotHasKey($id, $seen, 'An asset must not appear on more than one page.');
                $seen[$id] = true;
            }

            if ($page === 1) {
                $nextUrl = (string) $crawler->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
                parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
                self::assertSame($needle, $nextQuery['q'] ?? null);
                self::assertSame('2', $nextQuery['page'] ?? null);
            }
        }

        $expectedIds = [];
        foreach ($assets as $asset) {
            $id = $asset->getId();
            self::assertNotNull($id);
            $expectedIds[] = $id;
        }
        self::assertEqualsCanonicalizing($expectedIds, array_map('intval', array_keys($seen)));

        $response = $client->getResponse();
        self::assertSame('private, no-store', $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function testFiltersAndExistingStorageLinkArePreservedAcrossPagination(): void
    {
        $client = static::createClient();
        $needle = 'filtered-library-'.bin2hex(random_bytes(5));
        $folder = (new MediaFolder())
            ->setName('Pagination '.$needle)
            ->setSlug('pagination-'.bin2hex(random_bytes(5)));
        $this->em($client)->persist($folder);
        $this->em($client)->flush();
        $folderId = $folder->getId();
        self::assertNotNull($folderId);

        $this->persistAssets($client, $needle, 25, $folder, 'content', 'internal', 'text/plain');
        $outsideFolder = $this->asset($needle.'-outside-folder', null);
        $this->em($client)->persist($outsideFolder);
        $this->em($client)->flush();

        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));
        $query = [
            'q' => $needle,
            'module' => 'content',
            'storage' => 'internal',
            'type' => 'other',
            'folder' => (string) $folderId,
        ];
        $crawler = $client->request('GET', '/admin/storage?'.http_build_query($query));

        self::assertResponseIsSuccessful();
        $libraryUrl = (string) $crawler->selectLink('Vollständige Mediathek')->attr('href');
        self::assertSame('/admin/storage/library/all', parse_url($libraryUrl, PHP_URL_PATH));
        parse_str((string) parse_url($libraryUrl, PHP_URL_QUERY), $linkQuery);
        foreach ($query as $key => $value) {
            self::assertSame($value, $linkQuery[$key] ?? null);
        }

        $firstPage = $client->request('GET', $libraryUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '25 Treffer');
        self::assertSelectorCount(24, '.media-card');

        $nextUrl = (string) $firstPage->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
        parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
        foreach ($query as $key => $value) {
            self::assertSame($value, $nextQuery[$key] ?? null);
        }
        self::assertSame('2', $nextQuery['page'] ?? null);

        $client->request('GET', $nextUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Seite 2 von 2');
        self::assertSelectorCount(1, '.media-card');
    }

    public function testMalformedFolderAndPageFiltersAreRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('GET', '/admin/storage/library/all?folder=not-a-folder');
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/admin/storage/library/all?folder=999999999');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/storage/library/all?page=0');
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/admin/storage/library/all?page=not-a-number');
        self::assertResponseStatusCodeSame(400);
    }

    public function testLibraryRequiresItsOwnStoragePermission(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::CONTENT]));

        $client->request('GET', '/admin/storage/library/all');
        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<MediaAsset> */
    private function persistAssets(
        KernelBrowser $client,
        string $needle,
        int $count,
        ?MediaFolder $folder = null,
        string $module = 'content',
        string $storage = 'internal',
        string $mimeType = 'text/plain',
    ): array {
        $assets = [];
        for ($index = 0; $index < $count; ++$index) {
            $name = $needle.'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.dat';
            $asset = $this->asset($name, $folder, $module, $storage, $mimeType);
            $this->em($client)->persist($asset);
            $assets[] = $asset;
        }
        $this->em($client)->flush();

        return $assets;
    }

    private function asset(
        string $name,
        ?MediaFolder $folder,
        string $module = 'content',
        string $storage = 'internal',
        string $mimeType = 'text/plain',
    ): MediaAsset {
        return (new MediaAsset())
            ->setModuleKey($module)
            ->setStorageMode($storage)
            ->setLocation('/uploads/media/'.$module.'/'.$name)
            ->setOriginalName($name)
            ->setTitle($name)
            ->setCaption('Pagination test asset')
            ->setFolder($folder)
            ->setMimeType($mimeType)
            ->setFileSize(10);
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('media-library-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Media library test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
