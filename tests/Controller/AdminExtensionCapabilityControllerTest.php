<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\ExtensionPackage\ExtensionCapabilityAdministration;
use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionPackageInventory;
use App\ExtensionPackage\ExtensionPackageVerifier;
use App\ExtensionPackage\ExtensionPermissionStore;
use App\ExtensionPackage\ExtensionManifest;
use App\ExtensionRuntime\ExtensionRuntimeState;
use App\Security\CmsPermission;
use App\Service\AuditContextSanitizer;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminExtensionCapabilityControllerTest extends WebTestCase
{
    private string $temporaryRoot;
    private string $installRoot;
    private string $trustedKeysFile;
    private string $packageKey;
    private string $packageDirectory;
    private string $secretKey;
    private ExtensionManifest $manifest;
    private ExtensionPermissionStore $permissions;

    protected function tearDown(): void
    {
        if (isset($this->temporaryRoot) && is_dir($this->temporaryRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->temporaryRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }
            rmdir($this->temporaryRoot);
        }

        parent::tearDown();
    }

    public function testModuleListLinksOnlyToVerifiedInstalledPackages(): void
    {
        $client = $this->configuredClient();
        $invalidDirectory = $this->installRoot.'/module/bad-package';
        mkdir($invalidDirectory, 0700, true);
        file_put_contents($invalidDirectory.'/manifest.json', '{"tampered":true}');

        $client->request('GET', '/admin/modules');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="'.$this->reviewPath().'"]');
        self::assertSelectorNotExists('a[href="/admin/modules/extensions/module/bad-package"]');
        self::assertSelectorTextContains('body', 'Signiert und vollständig');
        self::assertSelectorTextContains('body', 'Gesperrt');
    }

    public function testReviewShowsRequestedCapabilitiesWithPrivateHeaders(): void
    {
        $client = $this->configuredClient();
        $client->request('GET', $this->reviewPath());

        self::assertResponseIsSuccessful();
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        $robots = strtolower((string) $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('noindex', $robots);
        self::assertStringContainsString('nofollow', $robots);
        self::assertSelectorTextContains('h1', 'Rechte prüfen: Testpaket '.$this->packageKey);
        self::assertSelectorTextContains('body', 'Inhalte lesen');
        self::assertSelectorTextContains('body', 'Einstellungen ändern');
        self::assertSelectorTextNotContains('body', 'HTTP-Anfragen');
        self::assertSelectorTextContains('body', 'bis sie widerrufen wird');
        self::assertStringNotContainsString($this->temporaryRoot, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('test-signer', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('signed test payload', (string) $client->getResponse()->getContent());
    }

    public function testGrantAndRevokeAreIndividualAuditedCsrfProtectedMutations(): void
    {
        $client = $this->configuredClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $auditRepository = $entityManager->getRepository(AuditLog::class);
        $grantCount = $auditRepository->count(['action' => 'extension.capability.granted']);
        $this->permissions->grant($this->manifest, 'content.read');
        $client->request('GET', $this->reviewPath());
        self::assertResponseIsSuccessful();

        $grantPath = $this->actionPath('settings.write', 'grant');
        $grantToken = $client->getCrawler()
            ->filter('form[action="'.$grantPath.'"] input[name="_token"]')
            ->attr('value');
        self::assertIsString($grantToken);

        $client->request('POST', $grantPath, ['_token' => $grantToken]);
        self::assertResponseRedirects($this->reviewPath());
        self::assertSame(['content.read', 'settings.write'], $this->permissions->approved($this->manifest));
        self::assertSame(0600, fileperms($this->temporaryRoot.'/permissions.json') & 0777);
        self::assertSame($grantCount + 1, $auditRepository->count(['action' => 'extension.capability.granted']));

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Freigegeben');
        $revokePath = $this->actionPath('settings.write', 'revoke');
        $revokeCount = $auditRepository->count(['action' => 'extension.capability.revoked']);
        $revokeToken = $client->getCrawler()
            ->filter('form[action="'.$revokePath.'"] input[name="_token"]')
            ->attr('value');
        self::assertIsString($revokeToken);

        $client->request('POST', $revokePath, ['_token' => $revokeToken]);
        self::assertResponseRedirects($this->reviewPath());
        self::assertSame(['content.read'], $this->permissions->approved($this->manifest));
        self::assertSame($revokeCount + 1, $auditRepository->count(['action' => 'extension.capability.revoked']));
    }

    public function testInvalidCsrfAndUnrequestedCapabilitiesLeavePermissionsUntouched(): void
    {
        $client = $this->configuredClient();
        $client->request('POST', $this->actionPath('settings.write', 'grant'), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->permissions->approved($this->manifest));

        $client->request('POST', $this->actionPath('settings.write', 'grant'), ['_token' => ['malformed']]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->permissions->approved($this->manifest));

        $client->request('GET', $this->reviewPath());
        self::assertResponseIsSuccessful();
        foreach (['media.write', 'php.execute'] as $capability) {
            $path = $this->actionPath($capability, 'grant');
            $tokenId = 'extension-capability-grant-module-'.$this->packageKey.'-'.$capability;
            $token = $this->csrfToken($client, $tokenId);
            $client->request('POST', $path, ['_token' => $token]);

            self::assertResponseStatusCodeSame(404);
            self::assertSame([], $this->permissions->approved($this->manifest));
        }
    }

    public function testAuthenticationAndSettingsPermissionProtectReviewAndMutation(): void
    {
        $client = $this->configuredClient(false);
        $client->request('GET', $this->reviewPath());
        self::assertResponseRedirects('/login');

        $client->loginUser($this->createUser($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]));
        $client->request('GET', $this->reviewPath());
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', $this->actionPath('settings.write', 'grant'), ['_token' => 'anything']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->permissions->approved($this->manifest));
    }

    public function testAuditFailureRollsBackTheCapabilityChange(): void
    {
        $client = $this->configuredClient();
        $this->permissions->grant($this->manifest, 'content.read');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $auditRepository = $entityManager->getRepository(AuditLog::class);
        $auditCount = $auditRepository->count(['action' => 'extension.capability.granted']);
        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->expects(self::once())
            ->method('getCurrentRequest')
            ->willThrowException(new \RuntimeException('audit unavailable'));
        $audit = new AuditLogger(
            $entityManager,
            $client->getContainer()->get(Security::class),
            $requestStack,
            $client->getContainer()->get(AuditContextSanitizer::class),
        );
        $client->getContainer()->set(AuditLogger::class, $audit);

        $client->request('GET', $this->reviewPath());
        $grantPath = $this->actionPath('settings.write', 'grant');
        $token = $client->getCrawler()
            ->filter('form[action="'.$grantPath.'"] input[name="_token"]')
            ->attr('value');
        self::assertIsString($token);
        $client->request('POST', $grantPath, ['_token' => $token]);

        self::assertResponseRedirects($this->reviewPath());
        self::assertSame(['content.read'], $this->permissions->approved($this->manifest));
        self::assertSame($auditCount, $auditRepository->count(['action' => 'extension.capability.granted']));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'wegen eines Auditfehlers zurückgenommen');
    }

    public function testTamperedAndSymlinkedPackagesCannotBeReviewed(): void
    {
        $client = $this->configuredClient();
        $tamperedKey = 'tampered-'.bin2hex(random_bytes(3));
        $tamperedDirectory = $this->installRoot.'/module/'.$tamperedKey;
        $this->createPackage($tamperedDirectory, $tamperedKey, ['content.read']);
        file_put_contents($tamperedDirectory.'/manifest.json', '{}');

        $client->request('GET', '/admin/modules/extensions/module/'.$tamperedKey);
        self::assertResponseStatusCodeSame(404);

        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable.');
        }
        $linkedKey = 'linked-'.bin2hex(random_bytes(3));
        if (!symlink($this->packageDirectory, $this->installRoot.'/module/'.$linkedKey)) {
            self::markTestSkipped('Symlinks are unavailable.');
        }

        $client->request('GET', '/admin/modules/extensions/module/'.$linkedKey);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownMismatchedAndChecksumInvalidPackagesAreRejected(): void
    {
        $client = $this->configuredClient();
        $unknownKey = 'missing-'.bin2hex(random_bytes(3));
        $client->request('GET', '/admin/modules/extensions/module/'.$unknownKey);
        self::assertResponseStatusCodeSame(404);

        $routeKey = 'route-'.bin2hex(random_bytes(3));
        $signedKey = 'signed-'.bin2hex(random_bytes(3));
        $this->createPackage($this->installRoot.'/module/'.$routeKey, $signedKey, ['content.read']);
        $client->request('GET', '/admin/modules/extensions/module/'.$routeKey);
        self::assertResponseStatusCodeSame(404);

        $wrongTypeKey = 'type-'.bin2hex(random_bytes(3));
        $this->createPackage($this->installRoot.'/module/'.$wrongTypeKey, $wrongTypeKey, ['content.read'], 'theme');
        $client->request('GET', '/admin/modules/extensions/module/'.$wrongTypeKey);
        self::assertResponseStatusCodeSame(404);

        $checksumKey = 'checksum-'.bin2hex(random_bytes(3));
        $checksumDirectory = $this->installRoot.'/module/'.$checksumKey;
        $this->createPackage($checksumDirectory, $checksumKey, ['content.read']);
        file_put_contents($checksumDirectory.'/payload.txt', 'modified after signing');
        $client->request('GET', '/admin/modules/extensions/module/'.$checksumKey);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->permissions->approved($this->manifest));
    }

    /** @param list<string>|null $permissions */
    private function configuredClient(bool $login = true, ?array $permissions = null): KernelBrowser
    {
        $this->temporaryRoot = sys_get_temp_dir().'/wcp464-'.bin2hex(random_bytes(6));
        $this->installRoot = $this->temporaryRoot.'/installed';
        $this->trustedKeysFile = $this->temporaryRoot.'/trusted-keys.json';
        $this->packageKey = 'capability-'.bin2hex(random_bytes(4));
        $this->packageDirectory = $this->installRoot.'/module/'.$this->packageKey;
        mkdir($this->packageDirectory, 0700, true);

        $keyPair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
        file_put_contents($this->trustedKeysFile, json_encode([
            'test-signer' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
        ], JSON_THROW_ON_ERROR));
        $this->createPackage($this->packageDirectory, $this->packageKey, ['content.read', 'settings.write']);

        $policy = new ExtensionCapabilityPolicy();
        $verifier = new ExtensionPackageVerifier($this->trustedKeysFile, $policy);
        $this->manifest = $verifier->verify($this->packageDirectory);
        $this->permissions = new ExtensionPermissionStore($this->temporaryRoot.'/permissions.json', $policy);
        $client = static::createClient();
        $client->disableReboot();
        $container = $client->getContainer();
        $container->set(ExtensionPackageInventory::class, new ExtensionPackageInventory(
            $verifier,
            $this->permissions,
            new ExtensionRuntimeState($this->temporaryRoot.'/runtime-state.json'),
            $this->installRoot,
        ));
        $container->set(ExtensionCapabilityAdministration::class, new ExtensionCapabilityAdministration(
            $verifier,
            $this->permissions,
            $policy,
            $this->installRoot,
        ));
        if ($login) {
            $client->loginUser($this->createUser($client, $permissions ?? [CmsPermission::ACCESS, CmsPermission::SETTINGS]));
        }

        return $client;
    }

    /** @param list<string> $capabilities */
    private function createPackage(string $directory, string $key, array $capabilities, string $type = 'module'): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create extension test package.');
        }
        $payload = $directory.'/payload.txt';
        file_put_contents($payload, 'signed test payload');
        $manifest = json_encode([
            'schemaVersion' => 1,
            'type' => $type,
            'key' => $key,
            'name' => 'Testpaket '.$key,
            'version' => '1.2.3',
            'cmsConstraint' => '^1.0',
            'files' => ['payload.txt' => hash_file('sha256', $payload)],
            'capabilities' => $capabilities,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents($directory.'/manifest.json', $manifest);
        file_put_contents($directory.'/signature.json', json_encode([
            'signer' => 'test-signer',
            'signature' => base64_encode(sodium_crypto_sign_detached($manifest, $this->secretKey)),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('extension-capability-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Extension capability test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function reviewPath(): string
    {
        return '/admin/modules/extensions/module/'.$this->packageKey;
    }

    private function actionPath(string $capability, string $action): string
    {
        return $this->reviewPath().'/'.$capability.'/'.$action;
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $request = $client->getRequest();
        if (!$request instanceof Request || !$request->hasSession()) {
            throw new \RuntimeException('A current request session is required to create a CSRF token.');
        }

        $requestStack = $client->getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        try {
            $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken($tokenId)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }
}
