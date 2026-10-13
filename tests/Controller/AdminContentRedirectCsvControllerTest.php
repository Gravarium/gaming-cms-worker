<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminContentRedirectCsvControllerTest extends WebTestCase
{
    public function testExportRequiresLoginAndContentPermission(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content/redirects/export.csv');
        self::assertResponseRedirects('/login');

        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::ACCESS]);
        $client->loginUser($user);
        $client->request('GET', '/admin/content/redirects/export.csv');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExportUsesFiltersPrivateHeadersAndFormulaSafeCsv(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $entry = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('=HYPERLINK("https://attacker.invalid")')->setSlug('safe-target')->setBody('not exported');
        $redirect = new ContentRedirect($entry, ContentEntry::TYPE_NEWS, 'legacy-entry');
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($entry);
        $em->persist($redirect);
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/content/redirects/export.csv?q=legacy-entry&type=news');
        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="content-redirects.csv"', $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString("'=HYPERLINK", $client->getResponse()->getContent());
        self::assertStringNotContainsString('not exported', $client->getResponse()->getContent());
    }

    public function testMalformedExportFiltersReturnPrivateBadRequest(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($user);
        $client->request('GET', '/admin/content/redirects/export.csv?type=video');
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    /** @param list<string> $permissions */
    private function user(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $permissions): User
    {
        $user = (new User())->setEmail('redirect-export-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Redirect export')->setPermissions($permissions)->verifyEmail();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
