<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Download\DownloadDependency;
use App\Entity\Download\DownloadMirror;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminDownloadRelationsControllerTest extends WebTestCase
{
    public function testAdminCanManageDependenciesAndTrustedMirrorsAndCyclesFailClosed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $source = $this->package($client, 'Source mod');
        $target = $this->package($client, 'Required library');
        $sourceVersion = $this->version($client, $source, '1.0.0');
        $targetVersion = $this->version($client, $target, '1.0.0');
        $sourceId = $source->getId();
        $targetId = $target->getId();
        $sourceVersionId = $sourceVersion->getId();
        $targetVersionId = $targetVersion->getId();
        self::assertNotNull($sourceId);
        self::assertNotNull($targetId);
        self::assertNotNull($sourceVersionId);
        self::assertNotNull($targetVersionId);

        $client->request('GET', '/admin/downloads/'.$sourceId.'/relations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Source mod');
        $crawler = $client->request('GET', '/admin/downloads/versions/'.$sourceVersionId.'/dependencies/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Abhängigkeit hinzufügen')->form();
        $form['download_dependency[targetPackage]'] = (string) $targetId;
        $form['download_dependency[kind]'] = DownloadDependency::KIND_REQUIRES;
        $form['download_dependency[constraintExpression]'] = '>=1.0.0';
        $client->submit($form);

        self::assertResponseRedirects('/admin/downloads/versions/'.$sourceVersionId.'/relations');
        self::assertCount(1, $this->entityManager($client)->getRepository(DownloadDependency::class)->findBy([
            'version' => $sourceVersion,
            'targetPackage' => $target,
        ]));

        $crawler = $client->request('GET', '/admin/downloads/versions/'.$targetVersionId.'/dependencies/new');
        $form = $crawler->selectButton('Abhängigkeit hinzufügen')->form();
        $form['download_dependency[targetPackage]'] = (string) $sourceId;
        $form['download_dependency[kind]'] = DownloadDependency::KIND_REQUIRES;
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'cycle');
        self::assertCount(0, $this->entityManager($client)->getRepository(DownloadDependency::class)->findBy([
            'version' => $targetVersion,
            'targetPackage' => $source,
        ]));

        $crawler = $client->request('GET', '/admin/downloads/versions/'.$sourceVersionId.'/mirrors/new');
        $form = $crawler->selectButton('Mirror speichern')->form();
        $form['download_mirror[url]'] = 'https://mirror.example.test/source.zip';
        $form['download_mirror[trusted]']->tick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/downloads/versions/'.$sourceVersionId.'/relations');
        $mirrors = $this->entityManager($client)->getRepository(DownloadMirror::class)->findBy(['version' => $sourceVersion]);
        self::assertCount(1, $mirrors);
        self::assertTrue($mirrors[0]->isTrusted());

        $crawler = $client->request('GET', '/admin/downloads/versions/'.$sourceVersionId.'/mirrors/new');
        $form = $crawler->selectButton('Mirror speichern')->form();
        $form['download_mirror[url]'] = 'http://mirror.example.test/source.zip';
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager($client)->getRepository(DownloadMirror::class)->findBy(['version' => $sourceVersion]));
    }

    public function testDependencyDeletionRequiresOwnershipAndCsrf(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $source = $this->package($client, 'Source');
        $target = $this->package($client, 'Target');
        $other = $this->package($client, 'Other');
        $version = $this->version($client, $source, '1.0.0');
        $otherVersion = $this->version($client, $other, '1.0.0');
        $dependency = new DownloadDependency($version, $target, 'optional');
        $this->entityManager($client)->persist($dependency);
        $this->entityManager($client)->flush();

        $versionId = $version->getId();
        $otherVersionId = $otherVersion->getId();
        $dependencyId = $dependency->getId();
        self::assertNotNull($versionId);
        self::assertNotNull($otherVersionId);
        self::assertNotNull($dependencyId);

        $client->request(
            'POST',
            '/admin/downloads/versions/'.$otherVersionId.'/dependencies/'.$dependencyId.'/delete',
            ['_token' => 'forged'],
        );
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->entityManager($client)->find(DownloadDependency::class, $dependencyId));

        $deletePath = '/admin/downloads/versions/'.$versionId.'/dependencies/'.$dependencyId.'/delete';
        $client->request('POST', $deletePath, ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager($client)->find(DownloadDependency::class, $dependencyId));

        $crawler = $client->request('GET', '/admin/downloads/versions/'.$versionId.'/relations');
        $form = $crawler->filter('form[action*="/dependencies/'.$dependencyId.'/delete"]')->form();
        $client->request('POST', $deletePath, ['_token' => (string) $form['_token']->getValue()]);

        self::assertResponseRedirects('/admin/downloads/versions/'.$versionId.'/relations');
        self::assertNull($this->entityManager($client)->find(DownloadDependency::class, $dependencyId));
    }

    public function testAdminRelationsRequireStoragePermissionAndEnabledModule(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $package = $this->package($client, 'Protected');
        $version = $this->version($client, $package, '1.0.0');
        $versionId = $version->getId();
        self::assertNotNull($versionId);

        $client->loginUser($this->user($client, []));
        $client->request('GET', '/admin/downloads/versions/'.$versionId.'/relations');
        self::assertResponseStatusCodeSame(403);

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
            $client->request('GET', '/admin/downloads/versions/'.$versionId.'/relations');
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

    private function package(KernelBrowser $client, string $title): DownloadPackage
    {
        $slug = 'relation-'.bin2hex(random_bytes(6));
        $package = new DownloadPackage($title.' '.$slug, $slug, 'mod');
        $this->entityManager($client)->persist($package);
        $this->entityManager($client)->flush();

        return $package;
    }

    private function version(KernelBrowser $client, DownloadPackage $package, string $version): DownloadVersion
    {
        $record = (new DownloadVersion(
            $package,
            $version,
            $package->getSlug().'.zip',
            hash('sha256', $package->getSlug().$version),
            '2026/09/'.$package->getSlug().'.zip',
        ))->markScan(DownloadVersion::SCAN_CLEAN);
        $this->entityManager($client)->persist($record);
        $this->entityManager($client)->flush();

        return $record;
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('download-relations-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Download relations admin')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
