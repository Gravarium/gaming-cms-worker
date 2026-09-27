<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MediaAsset;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentEditorMediaPickerTest extends WebTestCase
{
    /** @var list<int> */
    private array $assetIds = [];

    private ?int $userId = null;

    public function testPickerRequiresContentManagementPermission(): void
    {
        $client = $this->client(false);

        try {
            $client->request('GET', '/admin/content/media-picker');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($client);
        }
    }

    public function testPickerReturnsOnlyResolvableActiveContentImages(): void
    {
        $client = $this->client(true);

        try {
            $valid = $this->asset($client, 'Campfire map');
            $otherModule = $this->asset($client, 'Campfire other module', 'image/jpeg', 'gaming');
            $unsupported = $this->asset($client, 'Campfire SVG', 'image/svg+xml');
            $pending = $this->asset($client, 'Campfire pending');
            $pending->markDeletionPending();
            $this->entityManager($client)->flush();
            $unsafe = $this->asset(
                $client,
                'Campfire unsafe location',
                'image/jpeg',
                'content',
                '/uploads/media/content/../private.jpg',
            );

            $client->request('GET', '/admin/content/media-picker?q=campfire&page=1');

            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStoreResponse($client);
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('campfire', $payload['query']);
            self::assertSame(1, $payload['page']);
            self::assertSame([(int) $valid->getId()], array_column($payload['items'], 'id'));
            self::assertSame('Campfire map', $payload['items'][0]['title']);
            self::assertSame('Alt Campfire map', $payload['items'][0]['altText']);
            self::assertStringStartsWith('/uploads/media/content/', $payload['items'][0]['url']);

            $em = $this->entityManager($client);
            self::assertInstanceOf(MediaAsset::class, $em->find(MediaAsset::class, $otherModule->getId()));
            self::assertInstanceOf(MediaAsset::class, $em->find(MediaAsset::class, $unsupported->getId()));
            $storedPending = $em->find(MediaAsset::class, $pending->getId());
            self::assertInstanceOf(MediaAsset::class, $storedPending);
            self::assertTrue($storedPending->isDeletionPending());
            self::assertInstanceOf(MediaAsset::class, $em->find(MediaAsset::class, $unsafe->getId()));
        } finally {
            $this->cleanup($client);
        }
    }

    public function testPickerSearchesAndPaginatesWithStableOrderingAndClampsPage(): void
    {
        $client = $this->client(true);

        try {
            $prefix = 'picker-'.bin2hex(random_bytes(5));
            for ($index = 0; $index < 26; ++$index) {
                $this->asset($client, sprintf('%s-%02d', $prefix, $index));
            }

            $client->request('GET', '/admin/content/media-picker?q='.$prefix.'&page=1');
            self::assertResponseIsSuccessful();
            $firstPage = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(26, $firstPage['total']);
            self::assertSame(2, $firstPage['pages']);
            self::assertCount(24, $firstPage['items']);

            $client->request('GET', '/admin/content/media-picker?q='.$prefix.'&page=1');
            $again = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(array_column($firstPage['items'], 'id'), array_column($again['items'], 'id'));

            $client->request('GET', '/admin/content/media-picker?q='.$prefix.'&page=99');
            self::assertResponseIsSuccessful();
            $lastPage = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(2, $lastPage['page']);
            self::assertCount(2, $lastPage['items']);
            self::assertSame([$prefix.'-24.jpg', $prefix.'-25.jpg'], array_column($lastPage['items'], 'originalName'));
        } finally {
            $this->cleanup($client);
        }
    }

    public function testInvalidScalarArrayAndOversizedPickerParametersFailClosed(): void
    {
        $client = $this->client(true);

        try {
            foreach ([
                '/admin/content/media-picker?q%5B%5D=campfire',
                '/admin/content/media-picker?page%5B%5D=1',
                '/admin/content/media-picker?page=0',
                '/admin/content/media-picker?page=abc',
                '/admin/content/media-picker?q='.str_repeat('x', 101),
                '/admin/content/media-picker?q=%FF',
            ] as $uri) {
                $client->request('GET', $uri);
                self::assertResponseStatusCodeSame(400, $uri);
                $this->assertPrivateNoStoreResponse($client);
            }
        } finally {
            $this->cleanup($client);
        }
    }

    public function testEditorRendersPickerUrlAndAccessibleDialog(): void
    {
        $client = $this->client(true);

        try {
            $crawler = $client->request('GET', '/admin/content/new');

            self::assertResponseIsSuccessful();
            self::assertSame(
                '/admin/content/media-picker',
                $crawler->filter('.content-editor')->attr('data-content-editor-media-picker-url-value'),
            );
            self::assertSelectorExists('dialog[data-content-editor-target="mediaPickerDialog"]');
            self::assertSelectorExists('[data-content-editor-target="mediaQuery"]');
            self::assertSelectorExists('[data-content-editor-target="mediaStatus"][aria-live="polite"]');
            self::assertSelectorTextContains('.content-editor__toolbar', '+ Medium');
        } finally {
            $this->cleanup($client);
        }
    }

    private function client(bool $canManageContent): KernelBrowser
    {
        $client = static::createClient();
        $user = (new User())
            ->setEmail('content-media-picker-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content media picker test')
            ->setPermissions($canManageContent ? [CmsPermission::ACCESS, CmsPermission::CONTENT] : [CmsPermission::ACCESS])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $em = $this->entityManager($client);
        $em->persist($user);
        $em->flush();
        $this->userId = $user->getId();
        $client->loginUser($user);

        return $client;
    }

    private function asset(
        KernelBrowser $client,
        string $title,
        string $mimeType = 'image/jpeg',
        string $moduleKey = 'content',
        ?string $location = null,
    ): MediaAsset {
        $asset = (new MediaAsset())
            ->setModuleKey($moduleKey)
            ->setStorageMode('internal')
            ->setLocation($location ?? '/uploads/media/content/'.bin2hex(random_bytes(8)).'.jpg')
            ->setOriginalName($title.'.jpg')
            ->setTitle($title)
            ->setAltText('Alt '.$title)
            ->setMimeType($mimeType)
            ->setFileSize(100);
        $em = $this->entityManager($client);
        $em->persist($asset);
        $em->flush();
        if ($asset->getId() !== null) {
            $this->assetIds[] = $asset->getId();
        }

        return $asset;
    }

    private function assertPrivateNoStoreResponse(KernelBrowser $client): void
    {
        $headers = $client->getResponse()->headers;
        $cacheControl = strtolower((string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('noindex, nofollow, noarchive', $headers->get('X-Robots-Tag'));
    }

    private function cleanup(KernelBrowser $client): void
    {
        $em = $this->entityManager($client);
        foreach ($this->assetIds as $id) {
            $asset = $em->find(MediaAsset::class, $id);
            if ($asset instanceof MediaAsset) {
                $em->remove($asset);
            }
        }
        if ($this->userId !== null) {
            $user = $em->find(User::class, $this->userId);
            if ($user instanceof User) {
                $em->remove($user);
            }
        }
        $em->flush();
        $this->assetIds = [];
        $this->userId = null;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
