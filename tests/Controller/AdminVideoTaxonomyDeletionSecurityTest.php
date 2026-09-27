<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminVideoTaxonomyDeletionSecurityTest extends WebTestCase
{
    public function testCategoryAndPlaylistDeletionRejectMissingAndInvalidCsrf(): void
    {
        $client = static::createClient();
        $category = $this->category($client, 'csrf-category');
        $categoryId = $category->getId();
        self::assertNotNull($categoryId);
        $playlist = $this->playlist($client, 'csrf-playlist');
        $playlistId = $playlist->getId();
        self::assertNotNull($playlistId);
        $user = $this->user($client, [CmsPermission::VIDEO]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            foreach ([
                '/admin/videos/category/'.$categoryId.'/delete',
                '/admin/videos/playlist/'.$playlistId.'/delete',
            ] as $path) {
                foreach ([null, 'invalid-video-taxonomy-token'] as $token) {
                    $payload = $token === null ? [] : ['_token' => $token];
                    $client->request('POST', $path, $payload);

                    self::assertResponseStatusCodeSame(403);
                    $this->assertCategoryAndPlaylistExist($client, $categoryId, $playlistId);
                }
            }
        } finally {
            $this->removeFixtures($client, [$categoryId], [$playlistId], $userId);
        }
    }

    public function testRenderedTokensDeleteOnlySelectedCategoryAndPlaylist(): void
    {
        $client = static::createClient();
        $selectedCategory = $this->category($client, 'selected-category');
        $selectedCategoryId = $selectedCategory->getId();
        self::assertNotNull($selectedCategoryId);
        $siblingCategory = $this->category($client, 'sibling-category');
        $siblingCategoryId = $siblingCategory->getId();
        self::assertNotNull($siblingCategoryId);

        $selectedPlaylist = $this->playlist($client, 'selected-playlist');
        $selectedPlaylistId = $selectedPlaylist->getId();
        self::assertNotNull($selectedPlaylistId);
        $siblingPlaylist = $this->playlist($client, 'sibling-playlist');
        $siblingPlaylistId = $siblingPlaylist->getId();
        self::assertNotNull($siblingPlaylistId);

        $user = $this->user($client, [CmsPermission::VIDEO]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            $crawler = $client->request('GET', '/admin/videos');
            self::assertResponseIsSuccessful();

            $categoryTokenNode = $crawler->filter(
                'form[action="/admin/videos/category/'.$selectedCategoryId.'/delete"] input[name="_token"]',
            );
            self::assertCount(1, $categoryTokenNode);
            $categoryToken = (string) $categoryTokenNode->attr('value');
            self::assertNotSame('', $categoryToken);

            $playlistTokenNode = $crawler->filter(
                'form[action="/admin/videos/playlist/'.$selectedPlaylistId.'/delete"] input[name="_token"]',
            );
            self::assertCount(1, $playlistTokenNode);
            $playlistToken = (string) $playlistTokenNode->attr('value');
            self::assertNotSame('', $playlistToken);

            $client->request('POST', '/admin/videos/category/'.$selectedCategoryId.'/delete', [
                '_token' => $categoryToken,
            ]);
            self::assertResponseRedirects('/admin/videos');

            $client->request('POST', '/admin/videos/playlist/'.$selectedPlaylistId.'/delete', [
                '_token' => $playlistToken,
            ]);
            self::assertResponseRedirects('/admin/videos');

            $entityManager = $this->em($client);
            $entityManager->clear();
            self::assertNull($entityManager->find(VideoCategory::class, $selectedCategoryId));
            self::assertInstanceOf(VideoCategory::class, $entityManager->find(VideoCategory::class, $siblingCategoryId));
            self::assertNull($entityManager->find(VideoPlaylist::class, $selectedPlaylistId));
            self::assertInstanceOf(VideoPlaylist::class, $entityManager->find(VideoPlaylist::class, $siblingPlaylistId));
        } finally {
            $this->removeFixtures(
                $client,
                [$selectedCategoryId, $siblingCategoryId],
                [$selectedPlaylistId, $siblingPlaylistId],
                $userId,
            );
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('video-taxonomy-delete-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video taxonomy deletion security test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function category(KernelBrowser $client, string $suffix): VideoCategory
    {
        $category = (new VideoCategory())
            ->setName('Video category '.$suffix)
            ->setSlug('video-category-'.$suffix.'-'.bin2hex(random_bytes(4)));
        $this->em($client)->persist($category);
        $this->em($client)->flush();

        return $category;
    }

    private function playlist(KernelBrowser $client, string $suffix): VideoPlaylist
    {
        $playlist = (new VideoPlaylist())
            ->setTitle('Video playlist '.$suffix)
            ->setSlug('video-playlist-'.$suffix.'-'.bin2hex(random_bytes(4)));
        $this->em($client)->persist($playlist);
        $this->em($client)->flush();

        return $playlist;
    }

    private function assertCategoryAndPlaylistExist(KernelBrowser $client, int $categoryId, int $playlistId): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        self::assertInstanceOf(VideoCategory::class, $entityManager->find(VideoCategory::class, $categoryId));
        self::assertInstanceOf(VideoPlaylist::class, $entityManager->find(VideoPlaylist::class, $playlistId));
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $playlistIds
     */
    private function removeFixtures(KernelBrowser $client, array $categoryIds, array $playlistIds, int $userId): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();

        foreach ($categoryIds as $categoryId) {
            $category = $entityManager->find(VideoCategory::class, $categoryId);
            if ($category instanceof VideoCategory) {
                $entityManager->remove($category);
            }
        }

        foreach ($playlistIds as $playlistId) {
            $playlist = $entityManager->find(VideoPlaylist::class, $playlistId);
            if ($playlist instanceof VideoPlaylist) {
                $entityManager->remove($playlist);
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
