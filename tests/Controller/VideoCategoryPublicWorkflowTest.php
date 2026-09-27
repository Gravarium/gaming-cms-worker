<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Video;
use App\Entity\VideoCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoCategoryPublicWorkflowTest extends WebTestCase
{
    public function testDirectoryListsOnlyEnabledCategoriesAndLinksToDetails(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(6));
        $enabled = (new VideoCategory())
            ->setName('Öffentliche Kategorie '.$suffix)
            ->setSlug('public-category-'.$suffix)
            ->setDescription('Eine sichtbare Kategorie.');
        $disabled = (new VideoCategory())
            ->setName('Verborgene Kategorie '.$suffix)
            ->setSlug('hidden-category-'.$suffix)
            ->setEnabled(false);
        $entityManager->persist($enabled);
        $entityManager->persist($disabled);
        $entityManager->flush();

        try {
            $client->request('GET', '/videos/categories');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Videokategorien');
            self::assertSelectorTextContains('body', $enabled->getName());
            self::assertSelectorTextNotContains('body', $disabled->getName());
            self::assertSelectorExists('a[href="/videos/categories/'.$enabled->getSlug().'"]');
        } finally {
            $entityManager->remove($enabled);
            $entityManager->remove($disabled);
            $entityManager->flush();
        }
    }

    public function testDetailShowsOnlyPublishedEnabledVideosFromSelectedCategory(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(6));
        $category = (new VideoCategory())
            ->setName('Kategorie '.$suffix)
            ->setSlug('category-'.$suffix)
            ->setDescription('Detailbeschreibung '.$suffix);
        $foreignCategory = (new VideoCategory())
            ->setName('Andere Kategorie '.$suffix)
            ->setSlug('other-category-'.$suffix);
        $visible = $this->video($category, 'Sichtbares Video '.$suffix, 'visible-'.$suffix, new \DateTimeImmutable('-1 hour'));
        $foreign = $this->video($foreignCategory, 'Fremdes Video '.$suffix, 'foreign-'.$suffix, new \DateTimeImmutable('-1 hour'));
        $draft = $this->video($category, 'Entwurf Video '.$suffix, 'draft-'.$suffix, null);
        $future = $this->video($category, 'Zukünftiges Video '.$suffix, 'future-'.$suffix, new \DateTimeImmutable('+1 day'));
        $disabled = $this->video($category, 'Deaktiviertes Video '.$suffix, 'disabled-'.$suffix, new \DateTimeImmutable('-1 hour'));
        $disabled->setEnabled(false);

        foreach ([$category, $foreignCategory, $visible, $foreign, $draft, $future, $disabled] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        try {
            $client->request('GET', '/videos/categories/'.$category->getSlug());

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $category->getName());
            self::assertSelectorTextContains('body', $category->getDescription());
            self::assertSelectorTextContains('body', $visible->getTitle());
            self::assertSelectorTextNotContains('body', $foreign->getTitle());
            self::assertSelectorTextNotContains('body', $draft->getTitle());
            self::assertSelectorTextNotContains('body', $future->getTitle());
            self::assertSelectorTextNotContains('body', $disabled->getTitle());
        } finally {
            foreach ([$visible, $foreign, $draft, $future, $disabled] as $video) {
                $entityManager->remove($video);
            }
            $entityManager->remove($category);
            $entityManager->remove($foreignCategory);
            $entityManager->flush();
        }
    }

    public function testMissingAndDisabledCategoriesReturnNotFound(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(6));
        $disabled = (new VideoCategory())
            ->setName('Deaktivierte Kategorie '.$suffix)
            ->setSlug('disabled-category-'.$suffix)
            ->setEnabled(false);
        $entityManager->persist($disabled);
        $entityManager->flush();

        try {
            $client->request('GET', '/videos/categories/'.$disabled->getSlug());
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos/categories/missing-category-'.$suffix);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $entityManager->remove($disabled);
            $entityManager->flush();
        }
    }

    public function testDisabledVideoModuleHidesCategoryRoutes(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $state = (new CmsModuleState())
            ->setModuleKey('video')
            ->updateVersion('1.0.0')
            ->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/videos/categories');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos/categories/any-category');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $entityManager->remove($state);
            $entityManager->flush();
        }
    }

    private function video(VideoCategory $category, string $title, string $slug, ?\DateTimeImmutable $publishedAt): Video
    {
        return (new Video())
            ->setCategory($category)
            ->setTitle($title)
            ->setSlug($slug)
            ->setDescription('Beschreibung '.$title)
            ->setSourceType(Video::SOURCE_EXTERNAL)
            ->setSourceUrl('https://videos.example.test/'.$slug)
            ->setPublishedAt($publishedAt);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
