<?php

declare(strict_types=1);

namespace App\Downloads\Relations;

use App\Entity\Download\DownloadDependency;
use App\Entity\Download\DownloadMirror;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Repository\Download\DownloadDependencyRepository;
use App\Repository\Download\DownloadMirrorRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DownloadReleaseRelationManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DownloadDependencyRepository $dependencies,
        private DownloadMirrorRepository $mirrors,
        private DownloadDependencyGraphPolicy $graphPolicy,
        private AuditLogger $audit,
    ) {
    }

    public function addDependency(
        DownloadVersion $version,
        DownloadPackage $target,
        string $kind,
        ?string $constraintExpression,
    ): DownloadDependency {
        if (!in_array($kind, DownloadDependency::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid dependency kind.');
        }
        if ($version->getPackage()->getId() === null || $target->getId() === null) {
            throw new \DomainException('Persist both packages before adding a dependency.');
        }
        if ($version->getPackage()->getId() === $target->getId()) {
            throw new \DomainException('A package cannot depend on itself.');
        }
        if ($this->dependencies->existsForVersionTarget($version, $target)) {
            throw new \DomainException('This package is already listed for the selected version.');
        }
        if ($kind === DownloadDependency::KIND_REQUIRES) {
            $this->graphPolicy->assertAcyclic($version->getPackage(), $target);
        }

        $dependency = new DownloadDependency($version, $target, $kind, $constraintExpression);
        $this->entityManager->persist($dependency);
        $this->audit->record(
            'download.dependency.create',
            $version,
            $version->getId(),
            'A download version dependency was created.',
            ['target_package_id' => $target->getId(), 'kind' => $kind],
        );
        $this->entityManager->flush();

        return $dependency;
    }

    public function addMirror(DownloadVersion $version, string $url, bool $trusted): DownloadMirror
    {
        $normalizedUrl = trim($url);
        if ($this->mirrors->existsForVersionUrl($version, $normalizedUrl)) {
            throw new \DomainException('This mirror is already listed for the selected version.');
        }

        $mirror = new DownloadMirror($version, $normalizedUrl, $trusted);
        $this->entityManager->persist($mirror);
        $this->audit->record(
            'download.mirror.create',
            $version,
            $version->getId(),
            'A download mirror was created.',
            ['trusted' => $trusted],
        );
        $this->entityManager->flush();

        return $mirror;
    }

    public function removeDependency(DownloadDependency $dependency): void
    {
        $version = $dependency->getVersion();
        $this->entityManager->remove($dependency);
        $this->audit->record(
            'download.dependency.delete',
            $version,
            $version->getId(),
            'A download version dependency was removed.',
            ['target_package_id' => $dependency->getTargetPackage()->getId()],
        );
        $this->entityManager->flush();
    }

    public function removeMirror(DownloadMirror $mirror): void
    {
        $version = $mirror->getVersion();
        $this->entityManager->remove($mirror);
        $this->audit->record(
            'download.mirror.delete',
            $version,
            $version->getId(),
            'A download mirror was removed.',
            ['trusted' => $mirror->isTrusted()],
        );
        $this->entityManager->flush();
    }
}
