<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MediaAsset;
use App\Entity\User;
use App\Entity\Video;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminVideoDeletionSecurityTest extends WebTestCase
{
    public function testVideoDeletionRequiresVideoPermissionEvenWithValidCsrf(): void
    {
        $client = static::createClient();
        $asset = $this->asset($client, 'permission-source.mp4');
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $video = $this->video($client, $asset, 'permission');
        $videoId = $video->getId();
        self::assertNotNull($videoId);
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/videos');
            self::assertResponseStatusCodeSame(403);

            $token = $this->csrf($client)->getToken('delete-video-'.$videoId)->getValue();
            $client->request('POST', '/admin/videos/'.$videoId.'/delete', [
                '_token' => $token,
            ]);

            self::assertResponseStatusCodeSame(403);
            $this->assertVideoAndAssetExist($client, $videoId, $assetId);
        } finally {
            $this->removeFixtures($client, [$videoId], $assetId, $userId);
        }
    }

    public function testVideoDeletionRejectsMissingAndInvalidCsrfWithoutMutation(): void
    {
        $client = static::createClient();
        $asset = $this->asset($client, 'csrf-source.mp4');
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $video = $this->video($client, $asset, 'csrf');
        $videoId = $video->getId();
        self::assertNotNull($videoId);
        $user = $this->user($client, [CmsPermission::VIDEO]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            foreach ([[], ['_token' => 'invalid-video-delete-token']] as $payload) {
                $client->request('POST', '/admin/videos/'.$videoId.'/delete', $payload);

                self::assertResponseStatusCodeSame(403);
                $this->assertVideoAndAssetExist($client, $videoId, $assetId);
            }
        } finally {
            $this->removeFixtures($client, [$videoId], $assetId, $userId);
        }
    }

    public function testRenderedTokenDeletesOnlySelectedVideoAndKeepsSharedMedia(): void
    {
        $client = static::createClient();
        $asset = $this->asset($client, 'shared-source.mp4');
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $selected = $this->video($client, $asset, 'selected');
        $selectedId = $selected->getId();
        self::assertNotNull($selectedId);
        $sibling = $this->video($client, $asset, 'sibling');
        $siblingId = $sibling->getId();
        self::assertNotNull($siblingId);
        $user = $this->user($client, [CmsPermission::VIDEO]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            $crawler = $client->request('GET', '/admin/videos');
            self::assertResponseIsSuccessful();
            $tokenNode = $crawler->filter('form[action="/admin/videos/'.$selectedId.'/delete"] input[name="_token"]');
            self::assertCount(1, $tokenNode);
            $token = (string) $tokenNode->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', '/admin/videos/'.$selectedId.'/delete', [
                '_token' => $token,
            ]);

            self::assertResponseRedirects('/admin/videos');
            $entityManager = $this->em($client);
            $entityManager->clear();
            self::assertNull($entityManager->find(Video::class, $selectedId));
            self::assertInstanceOf(Video::class, $entityManager->find(Video::class, $siblingId));
            self::assertInstanceOf(MediaAsset::class, $entityManager->find(MediaAsset::class, $assetId));
        } finally {
            $this->removeFixtures($client, [$selectedId, $siblingId], $assetId, $userId);
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('video-delete-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video deletion security test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function asset(KernelBrowser $client, string $name): MediaAsset
    {
        $asset = (new MediaAsset())
            ->setModuleKey('video')
            ->setStorageMode('internal')
            ->setLocation('/uploads/media/video/'.bin2hex(random_bytes(6)).'-'.$name)
            ->setOriginalName($name)
            ->setTitle($name)
            ->setMimeType('video/mp4')
            ->setFileSize(123);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();

        return $asset;
    }

    private function video(KernelBrowser $client, MediaAsset $asset, string $suffix): Video
    {
        $video = (new Video())
            ->setTitle('Video deletion '.$suffix)
            ->setSlug('video-deletion-'.$suffix.'-'.bin2hex(random_bytes(4)))
            ->setDescription('Video deletion security fixture.')
            ->setSourceType(Video::SOURCE_UPLOAD)
            ->setMediaAsset($asset);
        $this->em($client)->persist($video);
        $this->em($client)->flush();

        return $video;
    }

    private function assertVideoAndAssetExist(KernelBrowser $client, int $videoId, int $assetId): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        self::assertInstanceOf(Video::class, $entityManager->find(Video::class, $videoId));
        self::assertInstanceOf(MediaAsset::class, $entityManager->find(MediaAsset::class, $assetId));
    }

    /** @param list<int> $videoIds */
    private function removeFixtures(KernelBrowser $client, array $videoIds, int $assetId, int $userId): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();

        foreach ($videoIds as $videoId) {
            $video = $entityManager->find(Video::class, $videoId);
            if ($video instanceof Video) {
                $entityManager->remove($video);
            }
        }

        $asset = $entityManager->find(MediaAsset::class, $assetId);
        if ($asset instanceof MediaAsset) {
            $entityManager->remove($asset);
        }

        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
        }

        $entityManager->flush();
    }

    private function csrf(KernelBrowser $client): CsrfTokenManagerInterface
    {
        return $client->getContainer()->get(CsrfTokenManagerInterface::class);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
