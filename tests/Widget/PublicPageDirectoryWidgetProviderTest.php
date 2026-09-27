<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicPageDirectoryWidgetProviderTest extends KernelTestCase
{
    public function testWidgetListsOnlyPublicPagesAndRespectsContentModule(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $state = $em->getRepository(CmsModuleState::class)->find('content');
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey('content');
                $em->persist($state);
            }
            $state->install('1.0.0')->setEnabled(true);

            $suffix = bin2hex(random_bytes(6));
            $publishedAt = new \DateTimeImmutable();
            $first = $this->page('first-'.$suffix, ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, false, $publishedAt);
            $second = $this->page('second-'.$suffix, ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, false, $publishedAt);
            $unlisted = $this->page('unlisted-'.$suffix, ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, true, $publishedAt);
            $draft = $this->page('draft-'.$suffix, ContentEntry::TYPE_PAGE, ContentEntry::STATUS_DRAFT, false, $publishedAt);
            $news = $this->page('news-'.$suffix, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, false, $publishedAt);

            foreach ([$first, $second, $unlisted, $draft, $news] as $entry) {
                $em->persist($entry);
            }
            $em->flush();

            $firstId = $first->getId();
            $secondId = $second->getId();
            $unlistedId = $unlisted->getId();
            $draftId = $draft->getId();
            $newsId = $news->getId();
            self::assertNotNull($firstId);
            self::assertNotNull($secondId);
            self::assertNotNull($unlistedId);
            self::assertNotNull($draftId);
            self::assertNotNull($newsId);

            $registry = self::getContainer()->get(WidgetRegistry::class);
            $definitions = $registry->availableDefinitions();
            self::assertContains(
                'content.pages',
                array_map(static fn (WidgetDefinition $definition): string => $definition->key, $definitions),
            );

            $result = $registry->data('content.pages', ['count' => 12]);
            self::assertIsArray($result['items'] ?? null);
            /** @var list<ContentEntry> $items */
            $items = $result['items'];
            $ids = array_map(static fn (ContentEntry $entry): ?int => $entry->getId(), $items);

            self::assertContains($firstId, $ids);
            self::assertContains($secondId, $ids);
            self::assertNotContains($unlistedId, $ids);
            self::assertNotContains($draftId, $ids);
            self::assertNotContains($newsId, $ids);

            $listedIds = array_values(array_filter($ids, static fn (?int $id): bool => in_array($id, [$firstId, $secondId], true)));
            $expectedListedIds = [$firstId, $secondId];
            rsort($expectedListedIds);
            self::assertSame($expectedListedIds, $listedIds);

            $state->setEnabled(false);
            $em->flush();
            self::assertSame([], $registry->data('content.pages', ['count' => 12]));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $em->clear();
        }
    }

    private function page(string $slug, string $type, string $status, bool $unlisted, \DateTimeImmutable $publishedAt): ContentEntry
    {
        return (new ContentEntry())
            ->setType($type)
            ->setTitle('Page '.$slug)
            ->setSlug($slug)
            ->setBody('Directory widget test content.')
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setUnlisted($unlisted);
    }
}
