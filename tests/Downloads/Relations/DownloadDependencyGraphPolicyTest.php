<?php

declare(strict_types=1);

namespace App\Tests\Downloads\Relations;

use App\Downloads\Relations\DownloadDependencyGraphPolicy;
use App\Entity\Download\DownloadDependency;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DownloadDependencyGraphPolicyTest extends KernelTestCase
{
    public function testGraphTraversalFailsClosedWhenTheDepthLimitIsExceeded(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $packages = [];
        $versions = [];

        for ($index = 0; $index <= 34; ++$index) {
            $package = new DownloadPackage('Graph '.$suffix.' '.$index, 'graph-'.$suffix.'-'.$index, 'mod');
            $version = new DownloadVersion(
                $package,
                '1.0.0',
                'graph-'.$index.'.zip',
                hash('sha256', $suffix.'-'.$index),
                '2026/09/graph-'.$suffix.'-'.$index.'.zip',
            );
            $packages[] = $package;
            $versions[] = $version;
            $entityManager->persist($package);
            $entityManager->persist($version);
        }

        for ($index = 1; $index < 34; ++$index) {
            $entityManager->persist(new DownloadDependency(
                $versions[$index],
                $packages[$index + 1],
                DownloadDependency::KIND_REQUIRES,
            ));
        }
        $entityManager->flush();

        $policy = self::getContainer()->get(DownloadDependencyGraphPolicy::class);
        $this->expectException(\DomainException::class);
        $policy->assertAcyclic($packages[0], $packages[1]);
    }
}
