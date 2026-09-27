<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicPageDirectoryWidgetProvider implements WidgetProvider
{
    public function __construct(
        private ContentEntryRepository $entries,
        private RequestStack $requests,
    ) {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                'content.pages',
                'Veröffentlichte Seiten',
                'content',
                'widget/public_pages.html.twig',
            ),
        ];
    }

    public function data(string $key, array $config): array
    {
        $request = $this->requests->getCurrentRequest();
        $cacheKey = '_cms_widget_data_'.$key;

        /** @var list<ContentEntry>|null $items */
        $items = $request?->attributes->get($cacheKey);
        if (!is_array($items)) {
            $items = $this->findPublishedPages();
            $request?->attributes->set($cacheKey, $items);
        }

        $count = $config['count'] ?? 6;
        $count = is_int($count) ? max(1, min(12, $count)) : 6;

        return ['items' => array_slice($items, 0, $count)];
    }

    /** @return list<ContentEntry> */
    private function findPublishedPages(): array
    {
        return $this->entries->createQueryBuilder('entry')
            ->andWhere('entry.type = :type')
            ->setParameter('type', ContentEntry::TYPE_PAGE)
            ->andWhere('entry.status = :status')
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->andWhere('entry.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->andWhere('entry.unlisted = false')
            ->orderBy('entry.publishedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setMaxResults(12)
            ->getQuery()
            ->getResult();
    }
}
