<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityArchitectureTest extends TestCase
{
    public function testAuthenticationConfigurationKeepsMandatoryGuards(): void
    {
        $security = $this->readProjectFile('config/packages/security.yaml');

        self::assertStringContainsString('login_throttling:', $security);
        self::assertStringContainsString('max_attempts: 5', $security);
        self::assertStringContainsString('enable_csrf: true', $security);
        self::assertStringContainsString('user_checker: App\\Security\\UserChecker', $security);
        self::assertStringContainsString('- { path: ^/admin, roles: ROLE_USER }', $security);
        self::assertStringContainsString('- { path: ^/account, roles: ROLE_USER }', $security);
    }

    public function testPasskeysRequireVerifiedUsersAndExactServerConfiguration(): void
    {
        $webauthn = $this->readProjectFile('config/packages/webauthn.yaml');

        self::assertGreaterThanOrEqual(2, substr_count($webauthn, 'user_verification: required'));
        self::assertStringContainsString("rp_id: '%env(WEBAUTHN_RELYING_PARTY_ID)%'", $webauthn);
        self::assertStringContainsString("'%env(WEBAUTHN_ALLOWED_ORIGIN)%'", $webauthn);
    }

    public function testPasskeyDeletionAndAuditShareOneFlushBoundary(): void
    {
        $source = $this->readProjectFile('src/Controller/PasskeyController.php');
        $remove = strpos($source, '$this->entityManager->remove($credential);');
        $audit = strpos($source, "'security.passkey.deleted'");
        $flush = strpos($source, '$this->entityManager->flush();', $remove === false ? 0 : $remove);

        self::assertNotFalse($remove);
        self::assertNotFalse($audit);
        self::assertNotFalse($flush);
        self::assertLessThan($audit, $remove);
        self::assertLessThan($flush, $audit);
        self::assertStringNotContainsString('$this->credentials->remove($credential)', $source);
    }

    public function testCategoryDeletionIsDatabaseRestrictedAgainstConcurrentReuse(): void
    {
        $category = $this->readProjectFile('src/Entity/Category.php');
        $content = $this->readProjectFile('src/Entity/ContentEntry.php');
        $migration = $this->readProjectFile('migrations/Version20260921224000.php');

        self::assertStringContainsString("#[ORM\\JoinColumn(onDelete: 'RESTRICT')]", $category);
        self::assertStringContainsString("#[ORM\\JoinColumn(onDelete: 'RESTRICT')]\n    private ?Category \$category = null;", $content);
        self::assertStringContainsString('FK_CONTENT_ENTRY_CATEGORY FOREIGN KEY (category_id) REFERENCES content_category (id) ON DELETE RESTRICT', $migration);
        self::assertStringContainsString('FK_CONTENT_CATEGORY_PARENT FOREIGN KEY (parent_id) REFERENCES content_category (id) ON DELETE RESTRICT', $migration);
    }

    public function testStructuredMediaReferencesRestrictAssetDeletion(): void
    {
        $guild = $this->readProjectFile('src/Entity/Guild.php');
        $video = $this->readProjectFile('src/Entity/Video.php');
        $migration = $this->readProjectFile('migrations/Version20260921225000.php');

        self::assertStringContainsString("#[ORM\\JoinColumn(onDelete: 'RESTRICT')]\n    private ?MediaAsset \$logo = null;", $guild);
        self::assertStringContainsString("#[ORM\\JoinColumn(onDelete: 'RESTRICT')]\n    private ?MediaAsset \$mediaAsset = null;", $video);
        self::assertStringContainsString('FK_GUILD_LOGO FOREIGN KEY (logo_id) REFERENCES media_asset (id) ON DELETE RESTRICT', $migration);
        self::assertStringContainsString('FK_VIDEO_MEDIA FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT', $migration);
    }

    public function testExtensionInstallerRebindsStagedPackageToInitialManifest(): void
    {
        $installer = $this->readProjectFile('src/ExtensionPackage/ExtensionPackageInstaller.php');

        self::assertStringContainsString('$stagedManifest = $this->verifier->verify($stage);', $installer);
        self::assertStringContainsString('if (!$this->sameManifest($manifest, $stagedManifest))', $installer);
        self::assertStringContainsString('Extension package changed after its initial verification.', $installer);
    }

    public function testPageLayoutLifecycleSerializesContentAndLayoutMutations(): void
    {
        $content = $this->readProjectFile('src/Controller/AdminContentController.php');
        $layout = $this->readProjectFile('src/Controller/AdminLayoutController.php');

        self::assertStringContainsString('LockMode::PESSIMISTIC_WRITE', $content);
        self::assertStringContainsString('$entityManager->lock($entry, LockMode::PESSIMISTIC_WRITE);', $content);
        self::assertStringContainsString('->setLockMode(LockMode::PESSIMISTIC_WRITE)', $layout);
        self::assertStringContainsString("->andWhere('entry.type = :type')", $layout);
    }

    #[DataProvider('adminControllers')]
    public function testEveryAdminControllerHasAnExplicitPermissionBoundary(string $path): void
    {
        $source = $this->readProjectFile($path);
        $classPosition = strpos($source, 'final class ');
        self::assertNotFalse($classPosition, $path);

        $classPolicy = substr($source, 0, $classPosition);
        self::assertStringContainsString("#[Route('/admin", $source, $path);
        self::assertStringContainsString('#[IsGranted(', $classPolicy, $path);
    }

    public static function adminControllers(): iterable
    {
        $root = dirname(__DIR__, 2);
        $paths = glob($root.'/src/Controller/Admin*Controller.php');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            yield basename($path) => [str_replace($root.'/', '', $path)];
        }
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
