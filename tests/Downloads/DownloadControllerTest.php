<?php

declare(strict_types=1);

namespace App\Tests\Downloads;

use App\Entity\CmsModuleState;
use App\Downloads\DownloadPrivateStorage;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DownloadControllerTest extends WebTestCase
{
    public function testMemberDownloadIsNotVisibleToAnonymousUser(): void
    {
        $client = static::createClient();
        $package = (new DownloadPackage('Members', 'members', 'file'))->setVisibility('member');
        $this->em($client)->persist($package);
        $this->em($client)->flush();

        $client->request('GET', '/downloads/members');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDisabledDownloadModuleFailsClosed(): void
    {
        $client = static::createClient();
        $state = (new CmsModuleState())
            ->setModuleKey('downloads')
            ->updateVersion('1.0.0')
            ->setEnabled(false);
        $this->em($client)->persist($state);
        $this->em($client)->flush();

        try {
            $client->request('GET', '/downloads');

            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/downloads/private-package');

            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->em($client)->remove($state);
            $this->em($client)->flush();
        }
    }

    public function testAdminUploadRequiresCsrfAndRejectsExecutableExtension(): void
    {
        $client = static::createClient();
        $this->ensureDownloadsEnabled($client);
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $client->loginUser($user);

        $package = new DownloadPackage('Safe', 'safe', 'file');
        $this->em($client)->persist($package);
        $this->em($client)->flush();
        $id = $package->getId();
        self::assertNotNull($id);

        $path = sys_get_temp_dir().'/download-'.bin2hex(random_bytes(5)).'.php';
        file_put_contents($path, '<?php echo 1;');

        try {
            $client->request(
                'POST',
                '/admin/downloads/'.$id.'/upload',
                [
                    'download_version' => ['version' => '1.0'],
                    '_token' => 'invalid',
                ],
                ['download_version' => ['file' => new UploadedFile($path, 'bad.php', 'application/x-php', null, true)]],
            );

            self::assertResponseStatusCodeSame(403);
            self::assertSame(
                0,
                $this->em($client)->getRepository(DownloadVersion::class)->count([]),
            );
        } finally {
            @unlink($path);
        }
    }

    public function testValidCsrfStillRejectsExecutableUpload(): void
    {
        $client = static::createClient();
        $this->ensureDownloadsEnabled($client);
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $client->loginUser($user);

        $package = new DownloadPackage('Safe2', 'safe2', 'file');
        $this->em($client)->persist($package);
        $this->em($client)->flush();
        $id = $package->getId();
        self::assertNotNull($id);

        $crawler = $client->request('GET', '/admin/downloads/'.$id.'/upload');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotSame('', $token);

        $path = sys_get_temp_dir().'/download-'.bin2hex(random_bytes(5)).'.php';
        file_put_contents($path, '<?php echo 1;');

        try {
            $client->request(
                'POST',
                '/admin/downloads/'.$id.'/upload',
                [
                    'download_version' => ['version' => '1.0'],
                    '_token' => $token,
                ],
                ['download_version' => ['file' => new UploadedFile($path, 'bad.php', 'application/x-php', null, true)]],
            );

            self::assertResponseStatusCodeSame(422);
            self::assertSame(
                0,
                $this->em($client)->getRepository(DownloadVersion::class)->count([]),
            );
        } finally {
            @unlink($path);
        }
    }

    public function testVersionUploadStoresCompatibilityAndReleaseNotesForPublicDetail(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $package = new DownloadPackage('Metadata Mod', 'metadata-mod', 'mod');
        $this->em($client)->persist($package);
        $this->em($client)->flush();
        $packageId = $package->getId();
        self::assertNotNull($packageId);

        $crawler = $client->request('GET', '/admin/downloads/'.$packageId.'/upload');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('textarea[name="download_version[compatibility]"]');
        self::assertSelectorExists('textarea[name="download_version[changelog]"]');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotSame('', $token);

        $path = sys_get_temp_dir().'/download-'.bin2hex(random_bytes(5)).'.zip';
        file_put_contents($path, 'safe archive bytes');

        try {
            $client->request(
                'POST',
                '/admin/downloads/'.$packageId.'/upload',
                [
                    'download_version' => [
                        'version' => '1.2.0',
                        'compatibility' => "Game 1.0\nGame 1.1",
                        'changelog' => "Fixed <script>alert(1)</script>\nImproved matchmaking",
                    ],
                    '_token' => $token,
                ],
                ['download_version' => ['file' => new UploadedFile($path, 'release.zip', 'application/zip', null, true)]],
            );

            self::assertResponseRedirects('/downloads/metadata-mod');
            $this->em($client)->clear();

            $version = $this->em($client)->getRepository(DownloadVersion::class)->findOneBy([
                'version' => '1.2.0',
            ]);
            self::assertInstanceOf(DownloadVersion::class, $version);
            self::assertSame(['Game 1.0', 'Game 1.1'], $version->getCompatibility());
            self::assertSame("Fixed <script>alert(1)</script>\nImproved matchmaking", $version->getChangelog());

            $client->request('GET', '/downloads/metadata-mod');
            self::assertResponseIsSuccessful();
            $content = $client->getResponse()->getContent();
            self::assertIsString($content);
            self::assertStringContainsString('Game 1.0, Game 1.1', $content);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
            self::assertStringContainsString('Improved matchmaking', $content);
            self::assertStringNotContainsString('<script>alert(1)</script>', $content);
        } finally {
            @unlink($path);
            $storedVersion = $this->em($client)->getRepository(DownloadVersion::class)->findOneBy([
                'version' => '1.2.0',
            ]);
            if ($storedVersion instanceof DownloadVersion) {
                try {
                    $storedPath = $client->getContainer()
                        ->get(DownloadPrivateStorage::class)
                        ->absolutePath($storedVersion->getStorageReference());
                    @unlink($storedPath);
                } catch (\Throwable) {
                }
                $this->em($client)->remove($storedVersion);
                $this->em($client)->flush();
            }
        }
    }

    public function testInvalidVersionShowsFieldErrorWithoutStoringUpload(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureDownloadsEnabled($client);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $package = new DownloadPackage('Validation Mod', 'validation-mod', 'mod');
        $this->em($client)->persist($package);
        $this->em($client)->flush();
        $packageId = $package->getId();
        self::assertNotNull($packageId);

        $crawler = $client->request('GET', '/admin/downloads/'.$packageId.'/upload');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $path = sys_get_temp_dir().'/download-'.bin2hex(random_bytes(5)).'.zip';
        file_put_contents($path, 'safe archive bytes');

        try {
            $client->request(
                'POST',
                '/admin/downloads/'.$packageId.'/upload',
                [
                    'download_version' => ['version' => 'bad version!'],
                    '_token' => $token,
                ],
                ['download_version' => ['file' => new UploadedFile($path, 'release.zip', 'application/zip', null, true)]],
            );

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Use letters, numbers, dots, plus signs, hyphens and underscores.');
            self::assertSame(0, $this->em($client)->getRepository(DownloadVersion::class)->count([]));
            self::assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    private function ensureDownloadsEnabled(KernelBrowser $client): void
    {
        $state = $this->em($client)->find(CmsModuleState::class, 'downloads');
        if ($state instanceof CmsModuleState) {
            $state->setEnabled(true);
            $this->em($client)->flush();
        }
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('download-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Download')
            ->setPermissions($permissions)
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
