<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityBoundaryBrowserTest extends WebTestCase
{
    #[DataProvider('protectedBrowserRoutes')]
    public function testAnonymousBrowserCannotReachProtectedPages(string $method, string $uri): void
    {
        $client = static::createClient();
        $client->request($method, $uri);

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageExposesPasswordAndPasskeyEntryPointsWithoutLeakingErrors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertSelectorExists('[data-passkey-login]');
        self::assertSelectorNotExists('.exception-message');
    }

    public function testProtectedMutationsRejectGetRequests(): void
    {
        $client = static::createClient();

        foreach ([
            '/admin/connectors/1/toggle',
            '/admin/connectors/1/delete',
            '/account/passkeys/example/delete',
        ] as $uri) {
            $client->request('GET', $uri);
            self::assertResponseStatusCodeSame(405, $uri);
        }
    }

    public static function protectedBrowserRoutes(): iterable
    {
        yield 'account security' => ['GET', '/account/security'];
        yield 'passkey list' => ['GET', '/account/passkeys'];
        yield 'passkey options' => ['POST', '/account/passkeys/options'];
        yield 'admin dashboard' => ['GET', '/admin'];
        yield 'users' => ['GET', '/admin/users'];
        yield 'modules' => ['GET', '/admin/modules'];
        yield 'storage' => ['GET', '/admin/storage'];
        yield 'media upload' => ['GET', '/admin/storage/media/upload'];
        yield 'connectors' => ['GET', '/admin/connectors'];
        yield 'recovery center' => ['GET', '/admin/backups'];
        yield 'guild area' => ['GET', '/guild-area'];
    }
}
