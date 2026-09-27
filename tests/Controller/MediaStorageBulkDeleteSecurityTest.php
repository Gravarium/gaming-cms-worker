<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Entity\Video;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MediaStorageBulkDeleteSecurityTest extends WebTestCase
{
    public function testBulkDeleteRequiresStoragePermission(): void
    {
        $client = static::createClient();
        $asset = $this->asset($client, 'content', 'permission.txt');
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            $client->request('POST', '/admin/storage/media/bulk', [
                'bulk_action' => 'delete',
                'assets' => [(string) $assetId],
            ]);

            self::assertResponseStatusCodeSame(403);
            $this->assertAssetExists($client, $assetId);
        } finally {
            $this->removeFixtures($client, [$assetId], $userId);
        }
    }

    public function testBulkDeleteRejectsMissingAndInvalidCsrfWithoutMutation(): void
    {
        $client = static::createClient();
        $asset = $this->asset($client, 'content', 'csrf.txt');
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            foreach ([
                [
                    'bulk_action' => 'delete',
                    'assets' => [(string) $assetId],
                ],
                [
                    '_token' => 'invalid-bulk-media-token',
                    'bulk_action' => 'delete',
                    'assets' => [(string) $assetId],
                ],
            ] as $payload) {
                $client->request('POST', '/admin/storage/media/bulk', $payload);

                self::assertResponseStatusCodeSame(403);
                $this->assertAssetExists($client, $assetId);
            }
        } finally {
            $this->removeFixtures($client, [$assetId], $userId);
        }
    }

    public function testBulkDeleteKeepsUsedMediaAndUnselectedSibling(): void
    {
        $client = static::createClient();
        $usedAsset = $this->asset($client, 'video', 'used.mp4');
        $usedAssetId = $usedAsset->getId();
        self::assertNotNull($usedAssetId);

        $siblingAsset = $this->asset($client, 'video', 'sibling.mp4');
        $siblingAssetId = $siblingAsset->getId();
        self::assertNotNull($siblingAssetId);

        $video = (new Video())
            ->setTitle('Protected bulk-delete video')
            ->setSlug('protected-bulk-delete-'.bin2hex(random_bytes(4)))
            ->setDescription('A video keeps its source media in use.')
            ->setSourceType(Video::SOURCE_UPLOAD)
            ->setMediaAsset($usedAsset);
        $this->em($client)->persist($video);
        $this->em($client)->flush();
        $videoId = $video->getId();
        self::assertNotNull($videoId);

        $user = $this->user($client, [CmsPermission::STORAGE]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            $token = $this->renderedBulkToken($client);
            $client->request('POST', '/admin/storage/media/bulk', [
                '_token' => $token,
                'bulk_action' => 'delete',
                'assets' => [(string) $usedAssetId],
            ]);

            self::assertResponseRedirects('/admin/storage');
            $this->assertAssetExists($client, $usedAssetId);
            $this->assertAssetExists($client, $siblingAssetId);
        } finally {
            $this->removeFixtures($client, [$usedAssetId, $siblingAssetId], $userId, $videoId);
        }
    }

    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('bulk-media-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Bulk media security test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function asset(KernelBrowser $client, string $moduleKey, string $name): MediaAsset
    {
        $mimeType = $moduleKey === 'video' ? 'video/mp4' : 'text/plain';
        $asset = (new MediaAsset())
            ->setModuleKey($moduleKey)
            ->setStorageMode('internal')
            ->setLocation('/uploads/media/'.$moduleKey.'/'.bin2hex(random_bytes(6)).'-'.$name)
            ->setOriginalName($name)
            ->setTitle($name)
            ->setMimeType($mimeType)
            ->setFileSize(123);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();

        return $asset;
    }

    private function assertAssetExists(KernelBrowser $client, int $assetId): void
    {
        $this->em($client)->clear();

        self::assertInstanceOf(MediaAsset::class, $this->em($client)->find(MediaAsset::class, $assetId));
    }

    private function renderedBulkToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/storage');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#media-bulk-form input[name="_token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @param list<int> $assetIds
     */
    private function removeFixtures(KernelBrowser $client, array $assetIds, int $userId, ?int $videoId = null): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();

        if ($videoId !== null) {
            $video = $entityManager->find(Video::class, $videoId);
            if ($video instanceof Video) {
                $entityManager->remove($video);
            }
        }

        foreach ($assetIds as $assetId) {
            $asset = $entityManager->find(MediaAsset::class, $assetId);
            if ($asset instanceof MediaAsset) {
                $entityManager->remove($asset);
            }
        }

        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
        }

        $entityManager->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
