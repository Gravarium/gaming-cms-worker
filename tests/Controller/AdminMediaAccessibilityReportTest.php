<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminMediaAccessibilityReportTest extends WebTestCase
{
    public function testAuthorizedReportIsPrivateAndLinksToMetadataEditorWithoutStorageDetails(): void
    {
        $client = static::createClient();
        $asset = $this->asset($client);
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $client->loginUser($this->user($client, [CmsPermission::ACCESS, CmsPermission::STORAGE]));

        $client->request('GET', '/admin/storage/accessibility');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'private, no-store, max-age=0');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
        self::assertSelectorTextContains('main', 'Bildalternativtexte prüfen');
        self::assertSelectorExists('a[href="/admin/storage/media/'.$assetId.'/edit"]');
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('dekorativen Bildern', $body);
        self::assertStringNotContainsString('/uploads/media/private-storage-', $body);
        self::assertStringNotContainsString('private-original-', $body);

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/storage/accessibility"]');
    }

    public function testReportRequiresStoragePermission(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/storage/accessibility');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($client, $asset, $user);
        }
    }

    public function testDisabledMediaModuleHidesDashboardLinkAndReturnsNotFound(): void
    {
        $client = static::createClient();
        $snapshot = $this->setMediaEnabled($client, false);
        $user = null;

        try {
            $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::STORAGE]);
            $client->loginUser($user);

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/admin/storage/accessibility"]');

            $client->request('GET', '/admin/storage/accessibility');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, null, $user);
            $this->restoreMediaState($client, $snapshot);
        }
    }

    public function testInvalidPageInputIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::STORAGE]);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/storage/accessibility?page%5B%5D=1');
            self::assertResponseStatusCodeSame(400);
            self::assertResponseHeaderSame('Cache-Control', 'private, no-store, max-age=0');
            $client->request('GET', '/admin/storage/accessibility?page=10001');
            self::assertResponseStatusCodeSame(400);
        } finally {
            $this->cleanup($client, null, $user);
        }
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('media-accessibility-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Media accessibility test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function asset(KernelBrowser $client): MediaAsset
    {
        $asset = (new MediaAsset())
            ->setModuleKey('content')
            ->setStorageMode('internal')
            ->setLocation('/uploads/media/private-storage-'.bin2hex(random_bytes(6)).'.png')
            ->setOriginalName('private-original-'.bin2hex(random_bytes(6)).'.png')
            ->setTitle('Media accessibility audit fixture')
            ->setMimeType('image/png')
            ->setFileSize(128);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();

        return $asset;
    }

    /** @param array{exists: bool, enabled: bool} $snapshot */
    private function restoreMediaState(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $this->em($client);
        $state = $entityManager->getRepository(CmsModuleState::class)->find('media');
        if (!$snapshot['exists']) {
            if ($state !== null) {
                $entityManager->remove($state);
            }
        } elseif ($state !== null) {
            $state->setEnabled($snapshot['enabled']);
            $entityManager->persist($state);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    /** @return array{exists: bool, enabled: bool} */
    private function setMediaEnabled(KernelBrowser $client, bool $enabled): array
    {
        $entityManager = $this->em($client);
        $existing = $entityManager->getRepository(CmsModuleState::class)->find('media');
        $snapshot = ['exists' => $existing !== null, 'enabled' => $existing?->isEnabled() ?? true];
        $state = $existing ?? (new CmsModuleState())->setModuleKey('media')->updateVersion('1.0.0');
        $state->setEnabled($enabled);
        $entityManager->persist($state);
        $entityManager->flush();

        return $snapshot;
    }

    private function cleanup(KernelBrowser $client, ?MediaAsset $asset, ?User $user): void
    {
        $entityManager = $this->em($client);
        if ($asset?->getId() !== null) {
            $stored = $entityManager->find(MediaAsset::class, $asset->getId());
            if ($stored instanceof MediaAsset) {
                $entityManager->remove($stored);
            }
        }
        if ($user?->getId() !== null) {
            $storedUser = $entityManager->find(User::class, $user->getId());
            if ($storedUser instanceof User) {
                $entityManager->remove($storedUser);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
