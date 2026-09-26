<?php

declare(strict_types=1);

namespace App\Service;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\Guild;
use App\Entity\MediaAsset;
use App\Entity\Video;
use App\Entity\PageLayout;
use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MediaAssetUsageResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteSettingsRepository $siteSettings,
        private readonly ContentEntryRepository $content,
        private readonly ContentBlockDocument $contentBlocks,
    ) {
    }

    /** @return list<string> */
    public function usages(MediaAsset $asset): array
    {
        $usages = [];
        $guildCount = $this->entityManager->getRepository(Guild::class)->count(['logo' => $asset]);
        if ($guildCount > 0) { $usages[] = 'Gildenlogo ('.$guildCount.')'; }

        $videoCount = $this->entityManager->getRepository(Video::class)->count(['mediaAsset' => $asset]);
        if ($videoCount > 0) { $usages[] = 'Video ('.$videoCount.')'; }

        $contentCount = count($this->content->findUsingMediaLocation($asset->getLocation()));
        if ($contentCount > 0) { $usages[] = 'Seiten/News ('.$contentCount.')'; }

        $editorCount = $this->editorDocumentUsageCount($asset);
        if ($editorCount > 0) { $usages[] = 'Seiten/News-Editor-Medien ('.$editorCount.')'; }

        $settings = $this->siteSettings->current();
        if ($settings->getLogoPath() === $asset->getLocation()) { $usages[] = 'Website-Logo'; }
        if ($settings->getFaviconPath() === $asset->getLocation()) { $usages[] = 'Favicon'; }

        foreach ($this->layoutUsages($asset) as $layout) $usages[] = 'Seitenlayout ('.$layout->getContext().')';
        return $usages;
    }

    public function isUsed(MediaAsset $asset): bool
    {
        return $this->usages($asset) !== [];
    }

    public function replaceUsages(MediaAsset $old, MediaAsset $replacement): int
    {
        $layoutReferences = $this->layoutUsages($old);
        if ($layoutReferences !== [] && !in_array($replacement->getMimeType(), ['image/jpeg','image/png','image/webp','image/gif','image/avif'], true)) throw new \DomainException('Layout-Bilder benötigen einen sicheren Bildersatz.');
        $changed = 0;
        foreach ($this->entityManager->getRepository(Guild::class)->findBy(['logo' => $old]) as $guild) {
            $guild->setLogo($replacement);
            ++$changed;
        }
        foreach ($this->entityManager->getRepository(Video::class)->findBy(['mediaAsset' => $old]) as $video) {
            $video->setMediaAsset($replacement);
            ++$changed;
        }
        foreach ($this->content->findUsingMediaLocation($old->getLocation()) as $entry) {
            $entry->setBody(str_replace($old->getLocation(), $replacement->getLocation(), $entry->getBody()));
            $entry->setExcerpt($entry->getExcerpt() === null ? null : str_replace($old->getLocation(), $replacement->getLocation(), $entry->getExcerpt()));
            ++$changed;
        }

        $settings = $this->siteSettings->current();
        if ($settings->getLogoPath() === $old->getLocation()) {
            $settings->setLogoPath($replacement->getLocation());
            ++$changed;
        }
        if ($settings->getFaviconPath() === $old->getLocation()) {
            $settings->setFaviconPath($replacement->getLocation());
            ++$changed;
        }

        foreach ($layoutReferences as $layout) {
            $document=$layout->getDocument();
            if (!is_array($document['widgets'] ?? null)) continue;
            foreach ($document['widgets'] as &$widget) if (is_array($widget) && is_array($widget['config'] ?? null) && ($widget['config']['imageId'] ?? null) === $old->getId()) $widget['config']['imageId']=$replacement->getId();
            unset($widget);
            $layout->replace($document);++$changed;
        }
        return $changed;
    }
    private function editorDocumentUsageCount(MediaAsset $asset): int
    {
        $assetId = $asset->getId();
        if ($assetId === null) {
            return 0;
        }

        $documents = $this->content->createQueryBuilder('entry')
            ->select('entry.editorDocument')
            ->andWhere('entry.editorDocument LIKE :assetReference')
            ->setParameter('assetReference', '%assetId%'.$assetId.'%')
            ->getQuery()
            ->getScalarResult();

        $usageCount = 0;
        foreach ($documents as $row) {
            $document = $row['editorDocument'] ?? null;
            if (!is_string($document)) {
                continue;
            }

            try {
                $blocks = $this->contentBlocks->decode($document)['blocks'];
            } catch (\InvalidArgumentException) {
                $pattern = '/"assetId"\\s*:\\s*'.preg_quote((string) $assetId, '/').'(?![0-9])/';
                if (preg_match($pattern, $document) === 1) {
                    ++$usageCount;
                }

                continue;
            }

            foreach ($blocks as $block) {
                if (($block['type'] ?? null) === 'media' && ($block['assetId'] ?? null) === $assetId) {
                    ++$usageCount;
                    break;
                }
            }
        }

        return $usageCount;
    }

    /** @return list<PageLayout> */
    private function layoutUsages(MediaAsset $asset): array
    {
        if ($asset->getId() === null) return [];
        $found=[];
        foreach ($this->entityManager->getRepository(PageLayout::class)->findAll() as $layout) {
            $context = $layout->getContext();
            if ($context !== 'home') {
                if (preg_match('/^page-([1-9][0-9]*)$/D', $context, $match) !== 1) continue;
                $entry = $this->entityManager->find(ContentEntry::class, (int) $match[1]);
                if (!$entry instanceof ContentEntry || $entry->getType() !== ContentEntry::TYPE_PAGE) continue;
            }
            $document=$layout->getDocument();
            if (!is_array($document['widgets'] ?? null)) continue;
            foreach ($document['widgets'] as $widget) if (is_array($widget) && is_array($widget['config'] ?? null) && ($widget['config']['imageId'] ?? null) === $asset->getId()) { $found[]=$layout;break; }
        }
        return $found;
    }
}
