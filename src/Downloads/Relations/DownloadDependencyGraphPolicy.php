<?php

declare(strict_types=1);

namespace App\Downloads\Relations;

use App\Entity\Download\DownloadPackage;
use App\Repository\Download\DownloadDependencyRepository;

final readonly class DownloadDependencyGraphPolicy
{
    private const MAX_PACKAGES = 500;
    private const MAX_DEPTH = 32;
    private const MAX_EDGES = 1500;
    private const MAX_EDGES_PER_PACKAGE = 250;

    public function __construct(private DownloadDependencyRepository $dependencies)
    {
    }

    public function assertAcyclic(DownloadPackage $source, DownloadPackage $target): void
    {
        $sourceId = $source->getId();
        $targetId = $target->getId();
        if ($sourceId === null || $targetId === null) {
            throw new \DomainException('Persist both packages before adding a dependency.');
        }
        if ($sourceId === $targetId) {
            throw new \DomainException('A package cannot require itself.');
        }

        /** @var list<array{0:int,1:int}> $pending */
        $pending = [[$targetId, 0]];
        /** @var array<int, true> $visited */
        $visited = [];
        $cursor = 0;
        $edgeCount = 0;

        while (isset($pending[$cursor])) {
            [$packageId, $depth] = $pending[$cursor];
            ++$cursor;

            if ($packageId === $sourceId) {
                throw new \DomainException('This required dependency would create a cycle.');
            }
            if (isset($visited[$packageId])) {
                continue;
            }
            if ($depth > self::MAX_DEPTH || count($visited) >= self::MAX_PACKAGES) {
                throw new \DomainException('The required dependency graph exceeds the safe validation limit.');
            }

            $visited[$packageId] = true;
            $targets = $this->dependencies->requiredTargetPackageIdsForPackageId(
                $packageId,
                self::MAX_EDGES_PER_PACKAGE + 1,
            );
            if (count($targets) > self::MAX_EDGES_PER_PACKAGE) {
                throw new \DomainException('The required dependency graph exceeds the safe validation limit.');
            }

            $edgeCount += count($targets);
            if ($edgeCount > self::MAX_EDGES) {
                throw new \DomainException('The required dependency graph exceeds the safe validation limit.');
            }

            foreach ($targets as $nextPackageId) {
                $pending[] = [$nextPackageId, $depth + 1];
            }
        }
    }
}
