<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ModuleStorageSetting;
use App\Entity\User;
use App\Repository\ModuleStorageSettingRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminStorageSettingAccessSecurityTest extends WebTestCase
{
    public function testConnectorManagerWithoutStoragePermissionCannotViewOrChangeDownloadsSetting(): void
    {
        $client = static::createClient();
        $original = $this->downloadsSettingState($client);
        $client->loginUser($this->createUser($client, 'connector-only', [CmsPermission::CONNECTORS]));

        $client->request('GET', '/admin/storage/downloads');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/storage/downloads', [
            'module_storage_setting' => [
                'storageMode' => ModuleStorageSetting::MODE_EXTERNAL,
                'externalBaseUrl' => 'https://attacker.example.test/downloads',
                '_token' => 'forged-token',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->assertDownloadsSettingState($client, $original);
    }

    public function testStorageManagerCannotChangeDownloadsSettingWithoutValidCsrfToken(): void
    {
        $client = static::createClient();
        $original = $this->downloadsSettingState($client);
        $client->loginUser($this->createUser($client, 'storage-csrf', [CmsPermission::STORAGE]));

        foreach ([null, 'forged-token'] as $token) {
            /** @var array<string, string> $formData */
            $formData = [
                'storageMode' => ModuleStorageSetting::MODE_EXTERNAL,
                'externalBaseUrl' => 'https://forged.example.test/downloads',
            ];
            if ($token !== null) {
                $formData['_token'] = $token;
            }

            $client->request('POST', '/admin/storage/downloads', [
                'module_storage_setting' => $formData,
            ]);
            self::assertResponseIsSuccessful();
            $this->assertDownloadsSettingState($client, $original);
        }
    }

    public function testRenderedStorageFormTokenCanSaveDownloadsSettingAndRestoresDatabaseState(): void
    {
        $client = static::createClient();
        $original = $this->downloadsSettingState($client);
        $client->loginUser($this->createUser($client, 'storage-manager', [CmsPermission::STORAGE]));
        $testUrl = 'https://cdn.example.test/downloads';

        try {
            $crawler = $client->request('GET', '/admin/storage/downloads');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[name="module_storage_setting"]');

            $form = $crawler->selectButton('Speicherziel übernehmen')->form();
            $form['module_storage_setting[storageMode]']->select(ModuleStorageSetting::MODE_EXTERNAL);
            $form['module_storage_setting[externalBaseUrl]']->setValue($testUrl);
            $client->submit($form);

            self::assertResponseRedirects('/admin/storage');

            $this->entityManager($client)->clear();
            $setting = $this->storageSettings($client)->findOneBy(['moduleKey' => 'downloads']);
            self::assertInstanceOf(ModuleStorageSetting::class, $setting);
            self::assertSame(ModuleStorageSetting::MODE_EXTERNAL, $setting->getStorageMode());
            self::assertSame($testUrl, $setting->getExternalBaseUrl());
        } finally {
            $this->restoreDownloadsSetting($client, $original);
        }
    }

    /**
     * @return array{storageMode: string, externalBaseUrl: ?string}|null
     */
    private function downloadsSettingState(KernelBrowser $client): ?array
    {
        $setting = $this->storageSettings($client)->findOneBy(['moduleKey' => 'downloads']);
        if (!$setting instanceof ModuleStorageSetting) {
            return null;
        }

        return [
            'storageMode' => $setting->getStorageMode(),
            'externalBaseUrl' => $setting->getExternalBaseUrl(),
        ];
    }

    /**
     * @param array{storageMode: string, externalBaseUrl: ?string}|null $expected
     */
    private function assertDownloadsSettingState(KernelBrowser $client, ?array $expected): void
    {
        $this->entityManager($client)->clear();
        self::assertSame($expected, $this->downloadsSettingState($client));
    }

    /**
     * @param array{storageMode: string, externalBaseUrl: ?string}|null $original
     */
    private function restoreDownloadsSetting(KernelBrowser $client, ?array $original): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $setting = $this->storageSettings($client)->findOneBy(['moduleKey' => 'downloads']);

        if ($original === null) {
            if ($setting instanceof ModuleStorageSetting) {
                $entityManager->remove($setting);
                $entityManager->flush();
            }

            return;
        }

        if (!$setting instanceof ModuleStorageSetting) {
            $setting = (new ModuleStorageSetting())->setModuleKey('downloads');
            $entityManager->persist($setting);
        }

        $setting
            ->setStorageMode($original['storageMode'])
            ->setExternalBaseUrl($original['externalBaseUrl']);
        $entityManager->flush();
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('storage-security-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Storage setting access '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();

        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function storageSettings(KernelBrowser $client): ModuleStorageSettingRepository
    {
        return $client->getContainer()->get(ModuleStorageSettingRepository::class);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
