<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoWorkspace\VideoSource;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoPlayerBrandingTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement("DELETE FROM video_workspace_source WHERE label LIKE 'Brand test%'");
            $db->executeStatement("DELETE FROM video WHERE slug LIKE 'brand-test-%'");
            $db->executeStatement("DELETE FROM cms_user WHERE email LIKE 'brand-test-%'");
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }
        parent::tearDown();
    }

    public function testPreviewRequiresManagerAndExplicitConsentAndNeverChangesProviderIframe(): void
    {
        $client = static::createClient(); $em = self::getContainer()->get(EntityManagerInterface::class);
        $state = $em->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) { $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test'); $em->persist($state); }
        $state->setEnabled(true);
        $video = (new Video())->setTitle('Brand test video')->setSlug('brand-test-'.bin2hex(random_bytes(6)))
            ->setDescription('Test')->setSourceType(Video::SOURCE_YOUTUBE)->setSourceUrl('https://youtu.be/abcdef12345')
            ->setPublishedAt(new \DateTimeImmutable('-1 minute'));
        $source = (new VideoSource())->setVideo($video);
        $source->configure('Brand test direct', 'mp4', 'https://cdn.example.test/video.mp4', 0, true, true);
        $reader = (new User())->setEmail('brand-test-'.bin2hex(random_bytes(6)).'@example.test')->setDisplayName('Reader')->setPassword('unused')->verifyEmail();
        $manager = (new User())->setEmail('brand-test-'.bin2hex(random_bytes(6)).'@example.test')->setDisplayName('Manager')->setPassword('unused')->verifyEmail()->setPermissions([CmsPermission::VIDEO]);
        foreach ([$video, $source, $reader, $manager] as $entity) { $em->persist($entity); } $em->flush();
        $url = '/admin/video-branding/videos/'.$video->getSlug();
        $client->loginUser($reader); $client->request('GET', $url); self::assertResponseStatusCodeSame(403);
        $client->getCookieJar()->clear(); $client->loginUser($manager);
        $crawler = $client->request('GET', $url); self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('video, iframe');
        self::assertStringNotContainsString('cdn.example.test', (string) $client->getResponse()->getContent());
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', $url, ['source' => (string) $source->getId(), 'branding' => 'own', 'brand_label' => '<script>alert(1)</script>', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-workspace-player]');
        self::assertSelectorTextContains('.branding-badge', '<script>alert(1)</script>');
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $client->getResponse()->getContent());
        $client->request('POST', $url, ['source' => (string) $source->getId(), 'branding' => 'off', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('.branding-badge');
        $client->request('POST', $url, ['source' => 'legacy', 'branding' => 'own', '_token' => $token]); self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, ['source' => 'legacy', 'branding' => 'off', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('iframe[src="https://www.youtube-nocookie.com/embed/abcdef12345"]');
        self::assertSelectorNotExists('.branding-badge');
        $client->request('POST', $url, ['source' => (string) $source->getId(), 'branding' => 'own', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }
}
