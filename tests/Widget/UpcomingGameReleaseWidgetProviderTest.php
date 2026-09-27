<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use App\Layout\LayoutRenderer;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Widget\UpcomingGameReleaseWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class UpcomingGameReleaseWidgetProviderTest extends KernelTestCase
{
    private const KEY = 'gaming.upcoming-releases';

    public function testPageBuilderRegistersTheWidgetAndGamingControlsAvailability(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $modules = $container->get(CmsModuleManager::class);
        $contentWasEnabled = $modules->isEnabled('content');
        $gamingWasEnabled = $modules->isEnabled('gaming');

        try {
            if (!$modules->isEnabled('content')) {
                $modules->setEnabled('content', true);
            }
            if (!$modules->isEnabled('gaming')) {
                $modules->setEnabled('gaming', true);
            }

            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(self::KEY);
            self::assertNotNull($definition);
            self::assertSame('gaming', $definition->module);
            self::assertSame('widget/upcoming_game_releases.html.twig', $definition->template);

            $availableKeys = array_map(
                static fn (WidgetDefinition $widget): string => $widget->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(self::KEY, $availableKeys);

            $validator = $container->get(LayoutValidator::class);
            self::assertSame(6, $validator->widgetSchema(self::KEY)['count']['default']);
            $document = $validator->defaults('nebula')->toArray();
            $region = $document['widgets'][0]['region'];
            $document['widgets'][] = [
                'id' => 'upcoming-releases',
                'type' => self::KEY,
                'region' => $region,
                'enabled' => true,
                'config' => ['count' => 2],
            ];
            $validated = $validator->validate($document);
            $renderer = $container->get(LayoutRenderer::class);
            $view = $renderer->view($validated);
            self::assertSame(self::KEY, $view['regions'][$region][1]['definition']->key);

            $modules->setEnabled('gaming', false);
            self::assertFalse($registry->available(self::KEY));
            self::assertSame([], $registry->data(self::KEY, ['count' => 2]));

            $viewWhileDisabled = $renderer->view($validated);
            self::assertCount(1, $viewWhileDisabled['regions'][$region]);
        } finally {
            if ($modules->isEnabled('gaming') !== $gamingWasEnabled) {
                $modules->setEnabled('gaming', $gamingWasEnabled);
            }
            if ($modules->isEnabled('content') !== $contentWasEnabled) {
                $modules->setEnabled('content', $contentWasEnabled);
            }
        }
    }

    public function testProviderShowsOnlyBoundedUpcomingPublicReleasesInStableOrder(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        /** @var list<Game|GameCatalogueEntry|GamePlatform|GameEdition|GameRelease> $entities */
        $entities = [];
        /** @var list<GameRelease> $validReleases */
        $validReleases = [];

        try {
            $releaseBase = new \DateTimeImmutable('today');
            $uniqueSuffix = bin2hex(random_bytes(6));
            $game = (new Game())
                ->setName('Release Game <script>alert(1)</script>')
                ->setSlug('release-game-'.$uniqueSuffix);
            $entry = new GameCatalogueEntry($game);
            $platform = new GamePlatform('PC', 'pc-'.$uniqueSuffix);
            $edition = new GameEdition($entry, 'Deluxe');

            $disabledGame = (new Game())
                ->setName('Disabled Game')
                ->setSlug('disabled-release-game-'.$uniqueSuffix)
                ->setEnabled(false);
            $disabledGameEntry = new GameCatalogueEntry($disabledGame);

            $disabledEntryGame = (new Game())
                ->setName('Disabled Entry Game')
                ->setSlug('disabled-entry-game-'.$uniqueSuffix);
            $disabledEntry = (new GameCatalogueEntry($disabledEntryGame))->setEnabled(false);

            $entities = [
                $game,
                $entry,
                $platform,
                $edition,
                $disabledGame,
                $disabledGameEntry,
                $disabledEntryGame,
                $disabledEntry,
            ];

            $cancelled = (new GameRelease($entry, $platform, 'EU', $releaseBase->modify('+1 day')))
                ->setStatus('cancelled');
            $past = new GameRelease($entry, $platform, 'EU', $releaseBase->modify('-1 day'));
            $disabledGameRelease = new GameRelease($disabledGameEntry, $platform, 'EU', $releaseBase->modify('+2 days'));
            $disabledEntryRelease = new GameRelease($disabledEntry, $platform, 'EU', $releaseBase->modify('+2 days'));

            foreach ([$cancelled, $past, $disabledGameRelease, $disabledEntryRelease] as $release) {
                $entities[] = $release;
            }

            for ($day = 3; $day <= 15; ++$day) {
                $release = new GameRelease($entry, $platform, 'EU', $releaseBase->modify(sprintf('+%d days', $day)));
                if ($day === 3) {
                    $release->setEdition($edition);
                }
                $entities[] = $release;
                $validReleases[] = $release;
            }

            $sameTimeRelease = new GameRelease($entry, $platform, 'EU', $releaseBase->modify('+3 days'));
            $entities[] = $sameTimeRelease;
            $validReleases[] = $sameTimeRelease;

            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            $expected = $validReleases;
            usort($expected, static function (GameRelease $left, GameRelease $right): int {
                $byTime = $left->getReleaseAt() <=> $right->getReleaseAt();
                if ($byTime !== 0) {
                    return $byTime;
                }

                return ($left->getId() ?? 0) <=> ($right->getId() ?? 0);
            });
            $expected = array_slice($expected, 0, 12);

            $provider = $container->get(UpcomingGameReleaseWidgetProvider::class);
            $result = $provider->data(self::KEY, ['count' => 99]);
            self::assertIsArray($result['items']);
            /** @var list<GameRelease> $actual */
            $actual = $result['items'];
            self::assertSame(
                array_map(static fn (GameRelease $release): int => $release->getId() ?? 0, $expected),
                array_map(static fn (GameRelease $release): int => $release->getId() ?? 0, $actual),
            );
            self::assertCount(12, $actual);

            $limited = $provider->data(self::KEY, ['count' => 2]);
            self::assertCount(2, $limited['items']);
            self::assertSame($actual[0]->getId(), $limited['items'][0]->getId());
            self::assertSame([], $provider->data('unregistered.widget', []));

            $twig = $container->get(Environment::class);
            $rendered = $twig->render('widget/upcoming_game_releases.html.twig', ['data' => ['items' => [$actual[0]]]]);
            self::assertStringContainsString('Deluxe', $rendered);
            self::assertStringContainsString('PC', $rendered);
            self::assertStringContainsString('EU', $rendered);
            self::assertStringContainsString('/games/'.$game->getSlug(), $rendered);
            self::assertStringContainsString('/games/releases', $rendered);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered);
            self::assertStringNotContainsString('<script>alert(1)</script>', $rendered);

            $empty = $twig->render('widget/upcoming_game_releases.html.twig', ['data' => ['items' => []]]);
            self::assertStringContainsString('Aktuell sind keine kommenden Spielveröffentlichungen angekündigt.', $empty);
        } finally {
            if ($entityManager->isOpen()) {
                foreach (array_reverse($entities) as $entity) {
                    $id = $entity->getId();
                    if ($id === null) {
                        continue;
                    }

                    $managed = $entityManager->find($entity::class, $id);
                    if ($managed !== null) {
                        $entityManager->remove($managed);
                    }
                }
                $entityManager->flush();
            }
        }
    }
}
