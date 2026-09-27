<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use App\Layout\LayoutValidator;
use App\Video\Library\VideoLibraryBrowser;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class VideoLibraryWidgetProviderTest extends WebTestCase
{
    public function testVideoLibraryWidgetIsDiscoverablePlaceableAndLinksToThePublicLibrary(): void
    {
        $client = static::createClient();
        $previous = $this->setVideoModuleEnabled($client, true);

        try {
            $browser = $client->getContainer()->get(VideoLibraryBrowser::class);
            $before = $browser->countPublic();

            $suffix = bin2hex(random_bytes(5));
            $category = (new VideoCategory())->setName('Widget category '.$suffix)->setSlug('widget-'.$suffix);
            $playlist = (new VideoPlaylist())->setTitle('Widget playlist '.$suffix)->setSlug('widget-'.$suffix);
            $video = (new Video())
                ->setTitle('Widget library video')
                ->setSlug('widget-library-'.$suffix)
                ->setDescription('Public widget test video.')
                ->setSourceType(Video::SOURCE_YOUTUBE)
                ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
                ->setCategory($category)
                ->setPublishedAt(new \DateTimeImmutable('-1 hour'))
                ->addPlaylist($playlist);
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $em->persist($category);
            $em->persist($playlist);
            $em->persist($video);
            $em->flush();

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get('video.library');
            self::assertNotNull($definition);
            self::assertSame('video', $definition->module);
            self::assertTrue($registry->available('video.library'));

            $layout = $client->getContainer()->get(LayoutValidator::class)->defaults('nebula')->toArray();
            $layout['widgets'][] = [
                'id' => 'video-library-entry',
                'type' => 'video.library',
                'region' => 'hero',
                'enabled' => true,
                'config' => [],
            ];
            $validated = $client->getContainer()->get(LayoutValidator::class)->validate($layout)->toArray();
            self::assertContains('video.library', array_column($validated['widgets'], 'type'));

            $data = $registry->data('video.library', []);
            self::assertSame($before + 1, $data['videoCount']);
            $rendered = $client->getContainer()->get(Environment::class)->render('widget/video_library.html.twig', $data);
            self::assertStringContainsString('/videos/library', $rendered);
            self::assertStringContainsString('Videobibliothek öffnen', $rendered);
        } finally {
            $this->restoreVideoModuleEnabled($client, $previous);
        }
    }

    public function testDisabledVideoModuleSuppressesTheWidgetAndPlacement(): void
    {
        $client = static::createClient();
        $previous = $this->setVideoModuleEnabled($client, false);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available('video.library'));
            self::assertSame([], $registry->data('video.library', []));

            $validator = $client->getContainer()->get(LayoutValidator::class);
            $layout = $validator->defaults('nebula')->toArray();
            $layout['widgets'][] = [
                'id' => 'disabled-video-library',
                'type' => 'video.library',
                'region' => 'hero',
                'enabled' => true,
                'config' => [],
            ];
            try {
                $validator->validate($layout);
                self::fail('Disabled Video widget unexpectedly passed Page Builder validation.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        } finally {
            $this->restoreVideoModuleEnabled($client, $previous);
        }
    }

    private function setVideoModuleEnabled(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, bool $enabled): ?bool
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $state = $em->find(CmsModuleState::class, 'video');
        $previous = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('video');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previous;
    }

    private function restoreVideoModuleEnabled(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, ?bool $previous): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $state = $em->find(CmsModuleState::class, 'video');
        if ($state === null) {
            return;
        }
        if ($previous === null) {
            $em->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $em->flush();
    }
}
