<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminVideoManagementSecurityTest extends WebTestCase
{
    public function testAnonymousAndUnauthorizedUsersCannotManageVideos(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/videos');

        self::assertResponseRedirects('/login');

        $user = $this->createUser($client, 'reader', []);
        $client->loginUser($user);
        $client->request('GET', '/admin/videos');

        self::assertResponseStatusCodeSame(403);
    }

    public function testVideoManagerCanOpenLibraryAndDeleteRequiresCsrf(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'manager', [CmsPermission::VIDEO]);
        $video = (new Video())
            ->setTitle('Security test video '.bin2hex(random_bytes(5)))
            ->setSlug('security-test-video-'.bin2hex(random_bytes(5)))
            ->setDescription('Video used to verify administrative access and CSRF.');
        $this->entityManager($client)->persist($video);
        $this->entityManager($client)->flush();
        $id = $video->getId();
        self::assertNotNull($id);
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/videos');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Videos, Kategorien und Playlists');

        $client->request('POST', '/admin/videos/'.$id.'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(Video::class, $client->getContainer()->get(VideoRepository::class)->find($id));

        $crawler = $client->request('GET', '/admin/videos');
        $tokenField = $crawler->filter('form[action="/admin/videos/'.$id.'/delete"] input[name="_token"]');
        self::assertCount(1, $tokenField);
        $token = (string) $tokenField->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', '/admin/videos/'.$id.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/videos');
        $this->entityManager($client)->clear();
        self::assertNull($client->getContainer()->get(VideoRepository::class)->find($id));
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('video-'.$label.'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Video '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
