<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminMediaAccessibilityCsvControllerTest extends WebTestCase
{
    public function testAuthorizedCsvIsBoundedPrivateAndExcludesStorageDetails(): void
    {
        $client = static::createClient();
        $asset = (new MediaAsset())
            ->setModuleKey('content')->setStorageMode('internal')
            ->setLocation('/uploads/private-export-location-'.bin2hex(random_bytes(5)).'.png')
            ->setOriginalName('private-export-name-'.bin2hex(random_bytes(5)).'.png')
            ->setMimeType('image/png')->setFileSize(128);
        $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::STORAGE]);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();
        $assetId = $asset->getId();
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/storage/accessibility/export.csv');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="media-accessibility-report.csv"');
            self::assertResponseHeaderSame('X-Accessibility-Report-Truncated', 'false');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
            $body = (string) $client->getResponse()->getContent();
            self::assertStringContainsString("asset_id,mime_type,created_at_utc\r\n", $body);
            self::assertStringContainsString((string) $assetId.',image/png,', $body);
            self::assertStringNotContainsString('private-export-name-', $body);
            self::assertStringNotContainsString('private-export-location-', $body);
        } finally {
            $this->cleanup($client, $asset, $user);
        }
    }

    public function testCsvRequiresStoragePermission(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($user);
        try {
            $client->request('GET', '/admin/storage/accessibility/export.csv');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($client, null, $user);
        }
    }

    public function testDisabledMediaModuleReturnsNotFound(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $existing = $em->getRepository(CmsModuleState::class)->find('media');
        $snapshot = ['exists' => $existing !== null, 'enabled' => $existing?->isEnabled() ?? true];
        $state = $existing ?? (new CmsModuleState())->setModuleKey('media')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();
        $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::STORAGE]);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/storage/accessibility/export.csv');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, null, $user);
            $em = $this->em($client);
            $state = $em->getRepository(CmsModuleState::class)->find('media');
            if (!$snapshot['exists']) {
                if ($state !== null) { $em->remove($state); }
            } elseif ($state !== null) {
                $state->setEnabled($snapshot['enabled']);
                $em->persist($state);
            }
            $em->flush();
            $em->clear();
        }
    }

    /** @param list<string> $permissions */
    private function user($client, array $permissions): User
    {
        $user = (new User())->setEmail('media-csv-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Media CSV test')->setPermissions($permissions)->setPassword('unused-test-hash')->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();
        return $user;
    }

    private function cleanup($client, ?MediaAsset $asset, ?User $user): void
    {
        $em = $this->em($client);
        if ($asset?->getId() !== null && ($stored = $em->find(MediaAsset::class, $asset->getId())) !== null) { $em->remove($stored); }
        if ($user?->getId() !== null && ($storedUser = $em->find(User::class, $user->getId())) !== null) { $em->remove($storedUser); }
        $em->flush();
        $em->clear();
    }

    private function em($client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
