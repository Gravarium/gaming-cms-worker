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

        try {
            $selection = $first->getSlug().','.$second->getSlug();
            $client->request('GET', '/games/compare?games='.rawurlencode($selection));
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(2, '.game-comparison thead a');
            self::assertSelectorTextContains('.game-comparison', 'Studio '.$suffix);
            self::assertSelectorTextContains('.game-comparison', 'Compare Platform '.$suffix);
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control') ?? '');

            foreach ([$first->getSlug().','.$first->getSlug(), $first->getSlug().','.$hidden->getSlug(),
                'bad slug,'.$second->getSlug(), $selection.',extra-fourth,another-fourth'] as $invalid) {
                $client->request('GET', '/games/compare?games='.rawurlencode($invalid));
                self::assertResponseStatusCodeSame(404);
            }
            $client->request('GET', '/games/compare?games[]=bad');
            self::assertResponseStatusCodeSame(404);

            $module->setEnabled(false);
            $em->flush();
            $client->request('GET', '/games/compare?games='.rawurlencode($selection));
            self::assertResponseStatusCodeSame(404);
        } finally {
            $module->setEnabled($prior ?? true);
            if ($prior === null) {
                $em->remove($module);
            }
            foreach ([$release, $hiddenEntry, $secondEntry, $firstEntry, $platform, $hidden, $second, $first] as $entity) {
                $em->remove($entity);
            }
            $em->flush();
        }
    }
}
