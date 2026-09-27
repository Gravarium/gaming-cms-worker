<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Download\DownloadPackage;
use App\Entity\User;
use App\Repository\Download\DownloadPackageRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminDownloadCatalogueControllerTest extends WebTestCase
{
    public function testStorageManagerCanCreateAndEditPackageSettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('GET', '/admin/downloads');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Es wurden noch keine Download-Pakete angelegt.');

        $client->request('GET', '/admin/downloads/new');
        self::assertResponseIsSuccessful();
        $client->submitForm('Paket anlegen', [
            'download_package[title]' => 'Tactical Mod',
            'download_package[slug]' => 'tactical-mod',
            'download_package[type]' => 'mod',
            'download_package[visibility]' => 'public',
            'download_package[enabled]' => '1',
        ]);

        self::assertResponseRedirects('/admin/downloads');
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Tactical Mod');
        self::assertSelectorTextContains('main', 'Version hochladen');

        $package = $this->packages($client)->findOneBy(['slug' => 'tactical-mod']);
        self::assertInstanceOf(DownloadPackage::class, $package);
        $id = $package->getId();
        self::assertNotNull($id);
        self::assertSame('/admin/downloads/'.$id.'/upload', $client->getCrawler()->filter('a[href*="/upload"]')->attr('href'));

        $crawler = $client->request('GET', '/admin/downloads/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Name, Slug und Typ bleiben nach dem Anlegen unverändert.');
        self::assertSelectorNotExists('input[name="download_package[title]"]');

        $form = $crawler->selectButton('Änderungen speichern')->form();
        $form['download_package[visibility]']->select('member');
        $form['download_package[enabled]']->untick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/downloads');
        $this->entityManager($client)->clear();
        $updated = $this->packages($client)->find($id);
        self::assertInstanceOf(DownloadPackage::class, $updated);
        self::assertSame('member', $updated->getVisibility());
        self::assertFalse($updated->isEnabled());
    }

    public function testInvalidSlugAndTypeDoNotCreateAPackage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('GET', '/admin/downloads/new');
        $client->submitForm('Paket anlegen', [
            'download_package[title]' => 'Invalid package',
            'download_package[slug]' => 'Invalid slug',
            'download_package[type]' => 'unexpected',
            'download_package[visibility]' => 'public',
            'download_package[enabled]' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->packages($client)->count([]));
    }

    public function testDuplicateSlugShowsAnErrorWithoutCreatingAPackage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $entityManager = $this->entityManager($client);
        $entityManager->persist(new DownloadPackage('Existing package', 'shared-download', 'file'));
        $entityManager->flush();
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('GET', '/admin/downloads/new');
        $client->submitForm('Paket anlegen', [
            'download_package[title]' => 'Second package',
            'download_package[slug]' => 'shared-download',
            'download_package[type]' => 'mod',
            'download_package[visibility]' => 'public',
            'download_package[enabled]' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'A package with this slug already exists.');
        self::assertSame(1, $this->packages($client)->count([]));
    }

    public function testMissingAndForgedCsrfTokensCannotCreatePackages(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $fields = [
            'title' => 'CSRF package',
            'slug' => 'csrf-package',
            'type' => 'file',
            'visibility' => 'public',
            'enabled' => '1',
        ];

        foreach ([null, 'forged-token'] as $token) {
            $payload = $fields;
            if ($token !== null) {
                $payload['_token'] = $token;
            }
            $client->request('POST', '/admin/downloads/new', ['download_package' => $payload]);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, $this->packages($client)->count([]));
        }
    }

    public function testPackageAdminRequiresStoragePermission(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, []));

        $client->request('GET', '/admin/downloads');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPackageAdminIsHiddenWhenDownloadsModuleIsDisabled(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $originalState = $entityManager->find(CmsModuleState::class, 'downloads');
        $originallyExisted = $originalState instanceof CmsModuleState;
        $originalEnabled = $originallyExisted ? $originalState->isEnabled() : true;

        if ($originalState instanceof CmsModuleState) {
            $originalState->setEnabled(false);
        } else {
            $originalState = (new CmsModuleState())
                ->setModuleKey('downloads')
                ->updateVersion('1.0.0')
                ->setEnabled(false);
            $entityManager->persist($originalState);
        }
        $entityManager->flush();
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        try {
            $client->request('GET', '/admin/downloads');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $currentState = $entityManager->find(CmsModuleState::class, 'downloads');
            if (!$originallyExisted) {
                if ($currentState instanceof CmsModuleState) {
                    $entityManager->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                $currentState->setEnabled($originalEnabled);
            }
            $entityManager->flush();
        }
    }

    private function ensureDownloadsEnabled(KernelBrowser $client): void
    {
        $state = $this->entityManager($client)->find(CmsModuleState::class, 'downloads');
        if ($state instanceof CmsModuleState && !$state->isEnabled()) {
            $state->setEnabled(true);
            $this->entityManager($client)->flush();
        }
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('download-admin-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Download admin')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function packages(KernelBrowser $client): DownloadPackageRepository
    {
        return $client->getContainer()->get(DownloadPackageRepository::class);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
