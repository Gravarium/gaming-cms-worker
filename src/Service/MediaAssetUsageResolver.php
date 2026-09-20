<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Guild;
use App\Entity\MediaAsset;
use App\Entity\Video;
use App\Repository\ContentEntryRepository;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MediaAssetUsageResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteSettingsRepository $siteSettings,
        private readonly ContentEntryRepository $content,
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

        $settings = $this->siteSettings->current();
        if ($settings->getLogoPath() === $asset->getLocation()) { $usages[] = 'Website-Logo'; }
        if ($settings->getFaviconPath() === $asset->getLocation()) { $usages[] = 'Favicon'; }

        return $usages;
    }

    public function isUsed(MediaAsset $asset): bool
    {
        return $this->usages($asset) !== [];
    }

    public function replaceUsages(MediaAsset $old, MediaAsset $replacement): int
    {
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

        return $changed;
    }
}
