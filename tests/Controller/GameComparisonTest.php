<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameComparisonTest extends WebTestCase
{
    public function testComparisonVisibilityValidationAndModuleGate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $module = $em->getRepository(CmsModuleState::class)->find('gaming');
        $prior = $module?->isEnabled();
        if (!$module instanceof CmsModuleState) {
            $module = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $em->persist($module);
        }
        $module->setEnabled(true);
        $em->flush();

        $suffix = bin2hex(random_bytes(5));
        $first = (new Game())->setName('Compare Alpha '.$suffix)->setSlug('compare-alpha-'.$suffix);
        $second = (new Game())->setName('Compare Beta '.$suffix)->setSlug('compare-beta-'.$suffix);
        $hidden = (new Game())->setName('Hidden Compare '.$suffix)->setSlug('compare-hidden-'.$suffix);
        $firstEntry = (new GameCatalogueEntry($first))->setDeveloper('Studio '.$suffix);
        $secondEntry = new GameCatalogueEntry($second);
        $hiddenEntry = (new GameCatalogueEntry($hidden))->setEnabled(false);
        $platform = new GamePlatform('Compare Platform '.$suffix, 'compare-platform-'.$suffix);
        $release = (new GameRelease($firstEntry, $platform, 'EU', new \DateTimeImmutable('2026-10-01')))->setStatus('released');
        foreach ([$first, $second, $hidden, $firstEntry, $secondEntry, $hiddenEntry, $platform, $release] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $ids = [
            [GameRelease::class, $release->getId()],
            [GameCatalogueEntry::class, $hiddenEntry->getId()],
            [GameCatalogueEntry::class, $secondEntry->getId()],
            [GameCatalogueEntry::class, $firstEntry->getId()],
            [GamePlatform::class, $platform->getId()],
            [Game::class, $hidden->getId()],
            [Game::class, $second->getId()],
            [Game::class, $first->getId()],
        ];
        $selection = $first->getSlug().','.$second->getSlug();
        $hiddenSelection = $first->getSlug().','.$hidden->getSlug();

        try {
            $client->request('GET', '/games/compare?games='.rawurlencode($selection));
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(2, '.game-comparison thead a');
            self::assertSelectorTextContains('.game-comparison', 'Studio '.$suffix);
            self::assertSelectorTextContains('.game-comparison', 'Compare Platform '.$suffix);
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control') ?? '');

            foreach ([$first->getSlug().','.$first->getSlug(), $hiddenSelection,
                'bad slug,'.$second->getSlug(), $selection.',extra-fourth,another-fourth'] as $invalid) {
                $client->request('GET', '/games/compare?games='.rawurlencode($invalid));
                self::assertResponseStatusCodeSame(404);
            }
            $client->request('GET', '/games/compare?games[]=bad');
            self::assertResponseStatusCodeSame(404);

        } finally {
            /** @var EntityManagerInterface $cleanup */
            $cleanup = $client->getContainer()->get(EntityManagerInterface::class);
            $cleanup->clear();
            $module = $cleanup->getRepository(CmsModuleState::class)->find('gaming');
            self::assertInstanceOf(CmsModuleState::class, $module);
            $module->setEnabled($prior ?? true);
            if ($prior === null) {
                $cleanup->remove($module);
            }
            foreach ($ids as [$class, $id]) {
                $entity = $cleanup->find($class, $id);
                if ($entity !== null) {
                    $cleanup->remove($entity);
                }
            }
            $cleanup->flush();
        }
    }

    public function testDisabledGamingHidesComparison(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $module = $em->getRepository(CmsModuleState::class)->find('gaming');
        $prior = $module?->isEnabled();
        if (!$module instanceof CmsModuleState) {
            $module = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $em->persist($module);
        }
        $module->setEnabled(false);
        $em->flush();

        try {
            $client->request('GET', '/games/compare');
            self::assertResponseStatusCodeSame(404);
        } finally {
            /** @var EntityManagerInterface $cleanup */
            $cleanup = $client->getContainer()->get(EntityManagerInterface::class);
            $cleanup->clear();
            $module = $cleanup->getRepository(CmsModuleState::class)->find('gaming');
            if ($module instanceof CmsModuleState) {
                if ($prior === null) {
                    $cleanup->remove($module);
                } else {
                    $module->setEnabled($prior);
                }
                $cleanup->flush();
            }
        }
    }
}
