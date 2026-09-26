<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminLayoutPageDirectoryTest extends WebTestCase
{
    public function testDirectoryRequiresSettingsPermission(): void
    {
        $auth = $this->authenticatedClient(false);

        try {
            $auth['client']->request('GET', '/admin/layout/pages');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($auth['client'], [], $auth['userId']);
        }
    }

    public function testLaterDirectoryPagesOpenLayoutContextsBeyondThePickerLimit(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $token = bin2hex(random_bytes(8));
        $entries = [];
        $entryIds = [];

        try {
            for ($index = 0; $index < 205; ++$index) {
                $entry = (new ContentEntry())
                    ->setAuthor($auth['user'])
                    ->setType(ContentEntry::TYPE_PAGE)
                    ->setTitle('Layout directory fixture')
                    ->setSlug(sprintf('layout-directory-%s-%03d', $token, $index))
                    ->setBody('Directory test page');
                $entityManager->persist($entry);
                $entries[] = $entry;
            }
            $entityManager->flush();

            foreach ($entries as $entry) {
                $id = $entry->getId();
                if ($id === null) {
                    throw new \LogicException('Persisted page has no identifier.');
                }
                $entryIds[] = $id;
            }

            $crawler = $client->request('GET', '/admin/layout/pages?'.http_build_query([
                'q' => $token,
                'page' => '9',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', '205 Seiten');
            self::assertSelectorTextContains('[role="status"]', 'Seite 9 von 9');
            self::assertCount(5, $crawler->filter('tbody tr'));
            $lastFiveIds = array_slice($entryIds, 200, 5);
            self::assertCount(5, $lastFiveIds);
            $lastPageId = null;
            foreach ($lastFiveIds as $position => $expectedId) {
                $link = $crawler->filter('tbody tr')->eq($position)->filter('a');
                self::assertSame('/admin/layout/page-'.$expectedId, $link->attr('href'));
                $lastPageId = $expectedId;
            }
            if ($lastPageId === null) {
                throw new \LogicException('The final page result is missing.');
            }
            $this->assertPrivateDirectoryHeaders($client);

            $crawler = $client->request('GET', '/admin/layout/pages?'.http_build_query([
                'q' => $token,
                'page' => '999999999',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'Seite 9 von 9');
            self::assertCount(1, $crawler->filter('a[href="/admin/layout/page-'.$lastPageId.'"]'));

            $client->request('GET', '/admin/layout/page-'.$lastPageId);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.editor-contexts a[href="/admin/layout/pages"]');
            self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        } finally {
            $this->cleanup($client, $entryIds, $auth['userId']);
        }
    }

    public function testSearchFindsTitlesAndSlugsAndRejectsMalformedParameters(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $token = bin2hex(random_bytes(8));
        $titleTerm = 'title-'.$token;
        $slugTerm = 'slug-'.$token;
        $titlePage = (new ContentEntry())
            ->setAuthor($auth['user'])
            ->setType(ContentEntry::TYPE_PAGE)
            ->setTitle('Page found by title '.$titleTerm)
            ->setSlug('unrelated-title-page-'.$token)
            ->setBody('Directory test page');
        $slugPage = (new ContentEntry())
            ->setAuthor($auth['user'])
            ->setType(ContentEntry::TYPE_PAGE)
            ->setTitle('Page found by slug '.$token)
            ->setSlug('page-found-by-'.$slugTerm)
            ->setBody('Directory test page');
        $entityManager->persist($titlePage);
        $entityManager->persist($slugPage);
        $entityManager->flush();

        $entryIds = [];
        foreach ([$titlePage, $slugPage] as $entry) {
            $id = $entry->getId();
            if ($id === null) {
                throw new \LogicException('Persisted page has no identifier.');
            }
            $entryIds[] = $id;
        }

        try {
            $crawler = $client->request('GET', '/admin/layout/pages?'.http_build_query(['q' => $titleTerm]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('tbody tr'));
            self::assertSelectorTextContains('tbody', 'Page found by title');
            $this->assertPrivateDirectoryHeaders($client);

            $crawler = $client->request('GET', '/admin/layout/pages?'.http_build_query(['q' => $slugTerm]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('tbody tr'));
            self::assertSelectorTextContains('tbody', 'Page found by slug');
            $this->assertPrivateDirectoryHeaders($client);

            $crawler = $client->request('GET', '/admin/layout/pages?'.http_build_query(['q' => 'missing-'.$token]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', '0 Seiten');
            self::assertSelectorTextContains('main', 'Keine passenden Seiten gefunden.');

            foreach ([
                '/admin/layout/pages?page=0',
                '/admin/layout/pages?page%5B%5D=1',
                '/admin/layout/pages?q%5B%5D=invalid',
                '/admin/layout/pages?q='.str_repeat('x', 101),
                '/admin/layout/pages?q=%FF',
            ] as $uri) {
                $client->request('GET', $uri);
                self::assertResponseStatusCodeSame(400);
            }
        } finally {
            $this->cleanup($client, $entryIds, $auth['userId']);
        }
    }

    /**
     * @return array{client: KernelBrowser, user: User, userId: int}
     */
    private function authenticatedClient(bool $settingsPermission = true): array
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $permissions = [CmsPermission::ACCESS];
        if ($settingsPermission) {
            $permissions[] = CmsPermission::SETTINGS;
        }

        $user = (new User())
            ->setEmail('layout-directory-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Layout directory test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();

        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Persisted test user has no identifier.');
        }

        $client->loginUser($user);

        return ['client' => $client, 'user' => $user, 'userId' => $userId];
    }

    /**
     * @param list<int> $entryIds
     */
    private function cleanup(KernelBrowser $client, array $entryIds, int $userId): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        foreach ($entryIds as $entryId) {
            $entry = $entityManager->find(ContentEntry::class, $entryId);
            if ($entry !== null) {
                $entityManager->remove($entry);
            }
        }

        $entityManager->flush();

        $user = $entityManager->find(User::class, $userId);
        if ($user !== null) {
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }

    private function assertPrivateDirectoryHeaders(KernelBrowser $client): void
    {
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
    }
}
