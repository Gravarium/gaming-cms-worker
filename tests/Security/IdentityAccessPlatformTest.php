<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AccessRole;
use App\Entity\User;
use App\Entity\UserSession;
use App\Security\CmsPermission;
use PHPUnit\Framework\TestCase;

final class IdentityAccessPlatformTest extends TestCase
{
    public function testEffectivePermissionsCombineRolesAndIndividualRights(): void
    {
        $role = (new AccessRole())->setKey('editor')->setName('Redaktion')->setPermissions([CmsPermission::CONTENT, CmsPermission::STORAGE]);
        $user = (new User())->setPermissions([CmsPermission::VIDEO])->addAccessRole($role);

        self::assertSame([CmsPermission::VIDEO, CmsPermission::CONTENT, CmsPermission::STORAGE], $user->getEffectivePermissions());
        self::assertTrue($user->hasPermission(CmsPermission::CONTENT));

        $role->setActive(false);
        self::assertSame([CmsPermission::VIDEO], $user->getEffectivePermissions());
    }

    public function testUnknownCmsPermissionsAreRejectedAtDomainBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new User())->setPermissions(['CMS_NOT_REAL']);
    }

    public function testUnknownRolePermissionsAreRejectedAtDomainBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AccessRole())->setPermissions(['CMS_NOT_REAL']);
    }

    public function testTimedLockAndSecurityVersion(): void
    {
        $user = new User();
        self::assertFalse($user->isLocked());
        self::assertSame(1, $user->getSecurityVersion());

        $user->lockUntil(new \DateTimeImmutable('+1 hour'), 'Prüfung');
        self::assertTrue($user->isLocked());
        self::assertSame('Prüfung', $user->getLockReason());
        self::assertSame(2, $user->getSecurityVersion());

        $user->unlock();
        self::assertFalse($user->isLocked());
    }

    public function testSessionStoresOnlyHashAndCanBeRevoked(): void
    {
        $user = new User();
        $session = new UserSession($user, 'private-session-id', '127.0.0.1', 'Test Browser');

        self::assertSame(hash('sha256', 'private-session-id'), $session->getSessionHash());
        self::assertFalse($session->isRevoked());
        self::assertSame(1, $session->getSecurityVersion());

        $session->revoke();
        self::assertTrue($session->isRevoked());
    }
}
