<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\Competition\Competition;
use App\Entity\Game;
use App\Layout\LayoutRenderer;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Widget\UpcomingCompetitionWidgetProvider;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class UpcomingCompetitionWidgetProviderTest extends KernelTestCase
{
    private const KEY = 'gaming.upcoming-competitions';

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
            self::assertSame('widget/upcoming_competitions.html.twig', $definition->template);

            $availableKeys = array_map(
                static fn ($widget): string => $widget->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(self::KEY, $availableKeys);

            $validator = $container->get(LayoutValidator::class);
            self::assertSame(6, $validator->widgetSchema(self::KEY)['count']['default']);
            $document = $validator->defaults('nebula')->toArray();
            $region = $document['widgets'][0]['region'];
            $document['widgets'][] = [
                'id' => 'upcoming-competitions',
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

    public function testProviderShowsOnlyUpcomingPublicOpenCompetitionsInStableOrder(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $games = [];
        $competitions = [];

        try {
            $sameStart = new \DateTimeImmutable('+2 days');
            [$gameA, $first] = $this->createCompetition($entityManager, 'first', $sameStart);
            [$gameB, $second] = $this->createCompetition($entityManager, 'second', $sameStart);
            [$gameLater, $later] = $this->createCompetition($entityManager, 'later', new \DateTimeImmutable('+3 days'));
            [$gamePrivate, $private] = $this->createCompetition($entityManager, 'private', new \DateTimeImmutable('+4 days'), public: false);
            [$gamePast, $past] = $this->createCompetition($entityManager, 'past', new \DateTimeImmutable('-1 day'));
            [$gameDraft, $draft] = $this->createCompetition($entityManager, 'draft', new \DateTimeImmutable('+5 days'), open: false);
            [$gameDisabled, $disabledGame] = $this->createCompetition($entityManager, 'disabled-game', new \DateTimeImmutable('+6 days'));
            $gameDisabled->setEnabled(false);

            $games = [$gameA, $gameB, $gameLater, $gamePrivate, $gamePast, $gameDraft, $gameDisabled];
            $competitions = [$first, $second, $later, $private, $past, $draft, $disabledGame];
            $entityManager->flush();

            $provider = $container->get(UpcomingCompetitionWidgetProvider::class);
            $result = $provider->data(self::KEY, ['count' => 12]);
            self::assertIsArray($result['items']);
            $visible = $result['items'];
            foreach ($visible as $item) {
                self::assertInstanceOf(Competition::class, $item);
            }
            self::assertSame(
                [$first->getId(), $second->getId(), $later->getId()],
                array_map(static fn (Competition $competition): ?int => $competition->getId(), $visible),
            );

            $limited = $provider->data(self::KEY, ['count' => 1]);
            self::assertIsArray($limited['items']);
            self::assertCount(1, $limited['items']);
            self::assertSame($first->getId(), $limited['items'][0]->getId());
            self::assertSame([], $provider->data('unregistered.widget', []));

            $twig = $container->get(Environment::class);
            $rendered = $twig->render('widget/upcoming_competitions.html.twig', ['data' => ['items' => [$first]]]);
            self::assertStringContainsString('Details und Anmeldung', $rendered);
            self::assertStringContainsString('/competitions/'.$first->getId(), $rendered);
            $empty = $twig->render('widget/upcoming_competitions.html.twig', ['data' => ['items' => []]]);
            self::assertStringContainsString('Aktuell sind keine öffentlichen Competitions zur Anmeldung geöffnet.', $empty);
        } finally {
            foreach ($competitions as $competition) {
                $entityManager->remove($competition);
            }
            $entityManager->flush();
            foreach ($games as $game) {
                $entityManager->remove($game);
            }
            $entityManager->flush();
        }
    }

    /** @return array{Game, Competition} */
    private function createCompetition(
        EntityManagerInterface $entityManager,
        string $suffix,
        \DateTimeImmutable $startsAt,
        bool $public = true,
        bool $open = true,
    ): array {
        $slug = 'widget-'.$suffix.'-'.bin2hex(random_bytes(6));
        $game = (new Game())->setName('Game '.$slug)->setSlug($slug);
        $competition = (new Competition())
            ->setGame($game)
            ->setName('Competition '.$slug)
            ->setSlug('competition-'.$slug)
            ->setStartsAt($startsAt);
        if (!$public) {
            $competition->setVisibility(Competition::VISIBILITY_PRIVATE);
        }
        if ($open) {
            $competition->open();
        }

        $entityManager->persist($game);
        $entityManager->persist($competition);

        return [$game, $competition];
    }
}
