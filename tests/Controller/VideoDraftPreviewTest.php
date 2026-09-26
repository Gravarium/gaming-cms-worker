<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoDraftPreviewTest extends WebTestCase
{
    public function testEditorCanPreviewSavedDraftDisabledAndScheduledVideosWithoutMakingThemPublic(): void
    {
        $client = static::createClient();
        $editor = $this->user($client, [CmsPermission::VIDEO]);
        $suffix = bin2hex(random_bytes(5));
        $now = new \DateTimeImmutable();
        $videos = [
            [$this->video('draft-'.$suffix, true, null), 'Entwurf: Dieses Video ist noch nicht veröffentlicht.'],
            [$this->video('disabled-'.$suffix, false, $now->modify('-1 day')), 'Dieses Video ist deaktiviert.'],
            [$this->video('scheduled-'.$suffix, true, $now->modify('+1 day')), 'Geplante Veröffentlichung am'],
        ];

        foreach ($videos as [$video]) {
            $this->em($client)->persist($video);
        }
        $this->em($client)->flush();
        $client->loginUser($editor);

        $crawler = $client->request('GET', '/admin/videos/new');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('a[href*="/preview"]')->count());

        foreach ($videos as [$video, $statusText]) {
            self::assertNotNull($video->getId());

            $client->request('GET', '/videos/'.$video->getSlug());
            self::assertResponseStatusCodeSame(404);

            $crawler = $client->request('GET', '/admin/videos/'.$video->getId().'/edit');
            self::assertResponseIsSuccessful();
            self::assertSame(
                1,
                $crawler->filter('a[href="/admin/videos/'.$video->getId().'/preview"]')->count(),
                'An existing video should link to its saved preview.',
            );

            $crawler = $client->request('GET', '/admin/videos/'.$video->getId().'/preview');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $video->getTitle());
            self::assertSelectorTextContains('[role="status"]', $statusText);
            self::assertSame('https://www.youtube-nocookie.com/embed/abcdefghijk', $crawler->filter('iframe')->attr('src'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSame(0, $crawler->filter('a[href="/videos/'.$video->getSlug().'"]')->count());
        }
    }

    public function testPreviewRequiresVideoManagementPermission(): void
    {
        $client = static::createClient();
        $video = $this->video('permission-'.bin2hex(random_bytes(5)), true, null);
        $this->em($client)->persist($video);
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($user);

        $client->request('GET', '/admin/videos/'.$video->getId().'/preview');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousVisitorCannotPreviewVideo(): void
    {
        $client = static::createClient();
        $video = $this->video('anonymous-'.bin2hex(random_bytes(5)), true, null);
        $this->em($client)->persist($video);
        $this->em($client)->flush();

        $client->request('GET', '/admin/videos/'.$video->getId().'/preview');

        self::assertResponseRedirects('/login');
    }

    public function testUnavailableVideoSourceShowsHelpfulPreviewMessage(): void
    {
        $client = static::createClient();
        $video = (new Video())
            ->setTitle('unavailable-'.bin2hex(random_bytes(5)))
            ->setSlug('unavailable-'.bin2hex(random_bytes(5)))
            ->setDescription('Preview without a playback source')
            ->setPublishedAt(null);
        $this->em($client)->persist($video);
        $editor = $this->user($client, [CmsPermission::VIDEO]);
        $client->loginUser($editor);

        $client->request('GET', '/admin/videos/'.$video->getId().'/preview');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Das Video kann derzeit nicht wiedergegeben werden.');
    }

    public function testDisabledVideoModuleAlsoBlocksThePreviewRoute(): void
    {
        $client = static::createClient();
        $video = $this->video('module-disabled-'.bin2hex(random_bytes(5)), true, null);
        $this->em($client)->persist($video);
        $editor = $this->user($client, [CmsPermission::VIDEO]);

        $em = $this->em($client);
        $existingState = $em->find(CmsModuleState::class, 'video');
        $previousEnabled = $existingState?->isEnabled();
        $state = $existingState ?? (new CmsModuleState())->setModuleKey('video')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        try {
            $client->loginUser($editor);
            $client->request('GET', '/admin/videos/'.$video->getId().'/preview');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $this->em($client);
            $currentState = $em->find(CmsModuleState::class, 'video');
            if ($previousEnabled === null) {
                if ($currentState instanceof CmsModuleState) {
                    $em->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                $currentState->setEnabled($previousEnabled);
            }
            $em->flush();
        }
    }

    private function video(string $slug, bool $enabled, ?\DateTimeImmutable $publishedAt): Video
    {
        return (new Video())
            ->setTitle('Video '.$slug)
            ->setSlug($slug)
            ->setDescription('Description '.$slug)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setEnabled($enabled)
            ->setPublishedAt($publishedAt);
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('video-preview-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video preview test')
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
