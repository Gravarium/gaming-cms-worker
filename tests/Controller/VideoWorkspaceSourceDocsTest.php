<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoWorkspaceSourceDocsTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement('DELETE FROM cms_user WHERE email LIKE ?', ['vwsd-test-%']);
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }
        parent::tearDown();
    }

    public function testManagerGetsSelectedProviderDocumentationAndExistingGatesRemain(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $state = $em->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test');
            $em->persist($state);
        }
        $state->setEnabled(true);
        $reader = $this->user($em, false);
        $manager = $this->user($em, true);
        $em->flush();

        $this->login($client, $reader);
        $client->request('GET', '/admin/video-workspace/sources/new');
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $manager);
        $crawler = $client->request('GET', '/admin/video-workspace/sources/new');
        self::assertResponseIsSuccessful();
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        $select = $crawler->filter('select[data-provider-documentation-select]');
        self::assertSame(1, $select->count());
        self::assertSame(29, $select->filter('option')->count());
        foreach ($select->filter('option') as $option) {
            $documentation = (string) $option->getAttribute('data-documentation');
            self::assertStringStartsWith('https://', $documentation);
            self::assertSame('', (string) parse_url($documentation, PHP_URL_USER));
            self::assertSame('', (string) parse_url($documentation, PHP_URL_PASS));
        }

        $selected = $select->filter('option[selected]');
        if ($selected->count() === 0) {
            $selected = $select->filter('option')->first();
        } else {
            $selected = $selected->first();
        }
        $link = $crawler->filter('[data-provider-documentation-link]');
        self::assertSame(1, $link->count());
        self::assertSame($selected->attr('data-documentation'), $link->attr('href'));
        self::assertSame($selected->attr('data-provider-label').' · Dokumentation', $link->text());
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener noreferrer', $link->attr('rel'));
        self::assertSelectorNotExists('iframe');

        $currentEm = $client->getContainer()->get(EntityManagerInterface::class);
        $currentState = $currentEm->find(CmsModuleState::class, 'video');
        self::assertInstanceOf(CmsModuleState::class, $currentState);
        $currentState->setEnabled(false);
        $currentEm->flush();
        $client->request('GET', '/admin/video-workspace/sources/new');
        self::assertResponseStatusCodeSame(404);
    }

    private function login(KernelBrowser $client, User $user): void
    {
        $client->getCookieJar()->clear();
        $freshUser = $client->getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $freshUser);
        $client->loginUser($freshUser);
    }

    private function user(EntityManagerInterface $em, bool $manager): User
    {
        $user = (new User())
            ->setEmail('vwsd-test-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('VWS docs test')
            ->setPassword('unused')
            ->verifyEmail()
            ->setPermissions($manager ? [CmsPermission::VIDEO] : []);
        $em->persist($user);
        return $user;
    }
}
