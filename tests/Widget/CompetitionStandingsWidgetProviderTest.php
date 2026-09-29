<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use App\Security\CmsPermission;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class CompetitionStandingsWidgetProviderTest extends WebTestCase
{
    private const WIDGET_KEY = 'gaming.competition-standings';

    public function testWidgetShowsOnlyPublicConfirmedStandingsAndUsesGamingModuleGate(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $moduleState = $entityManager->find(CmsModuleState::class, 'gaming');
        $createdModuleState = !$moduleState instanceof CmsModuleState;
        $wasEnabled = $moduleState instanceof CmsModuleState ? $moduleState->isEnabled() : true;
        $moduleState ??= (new CmsModuleState())->setModuleKey('gaming');
        $moduleState->setEnabled(true);
        $entityManager->persist($moduleState);
        $entityManager->flush();

        $suffix = bin2hex(random_bytes(6));

        try {
            $game = $this->createGame($entityManager, 'widget-game-'.$suffix);
            $public = $this->createCompetition($entityManager, $game, 'Public Cup '.$suffix, $suffix, true, true);
            $private = $this->createCompetition($entityManager, $game, 'Private Cup '.$suffix, $suffix.'-private', false);
            $disabledGame = $this->createGame($entityManager, 'disabled-game-'.$suffix);
            $disabled = $this->createCompetition($entityManager, $disabledGame, 'Disabled Game Cup '.$suffix, $suffix.'-disabled', true);
            $disabledGame->setEnabled(false);
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(self::WIDGET_KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertTrue($registry->available(self::WIDGET_KEY));

            $data = $registry->data(self::WIDGET_KEY, []);
            self::assertArrayHasKey('items', $data);
            self::assertIsArray($data['items']);

            /**
             * @var list<array{
             *     competition: Competition,
             *     standings: list<array{
             *         participant: CompetitionParticipant,
             *         played: int,
             *         wins: int,
             *         draws: int,
             *         losses: int,
             *         scoreFor: int,
             *         scoreAgainst: int,
             *         difference: int,
             *         points: int
             *     }>
             * }> $items
             */
            $items = $data['items'];

            $publicItem = null;
            $competitionNames = [];
            foreach ($items as $item) {
                $competitionNames[] = $item['competition']->getName();
                if ($item['competition'] === $public) {
                    $publicItem = $item;
                }
            }

            self::assertNotNull($publicItem);
            self::assertNotContains($private->getName(), $competitionNames);
            self::assertNotContains($disabled->getName(), $competitionNames);
            self::assertCount(2, $publicItem['standings']);
            self::assertSame('Alpha '.$suffix, $publicItem['standings'][0]['participant']->getName());
            self::assertSame(1, $publicItem['standings'][0]['played']);
            self::assertSame(3, $publicItem['standings'][0]['points']);
            self::assertSame('Beta '.$suffix, $publicItem['standings'][1]['participant']->getName());
            self::assertSame(0, $publicItem['standings'][1]['points']);

            $html = $container->get(Environment::class)->render($definition->template, [
                'config' => [],
                'data' => $data,
            ]);
            self::assertStringContainsString('Public Cup '.$suffix, $html);
            self::assertStringContainsString('Alpha '.$suffix, $html);
            self::assertStringContainsString('Beta '.$suffix, $html);
            self::assertStringContainsString('/competitions/'.$public->getId().'/leaderboard', $html);
            self::assertStringNotContainsString('Private Cup '.$suffix, $html);
            self::assertStringNotContainsString('Disabled Game Cup '.$suffix, $html);
            self::assertStringNotContainsString('Gamma '.$suffix, $html);
            self::assertStringNotContainsString('99 : 0', $html);

            $moduleState = $entityManager->find(CmsModuleState::class, 'gaming');
            self::assertInstanceOf(CmsModuleState::class, $moduleState);
            $moduleState->setEnabled(false);
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            $disabledRegistry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($disabledRegistry->available(self::WIDGET_KEY));
            self::assertSame([], $disabledRegistry->data(self::WIDGET_KEY, []));
        } finally {
            $restoreManager = $client->getContainer()->get(EntityManagerInterface::class);
            $restoreState = $restoreManager->find(CmsModuleState::class, 'gaming');
            if ($createdModuleState) {
                if ($restoreState instanceof CmsModuleState) {
                    $restoreManager->remove($restoreState);
                }
            } elseif ($restoreState instanceof CmsModuleState) {
                $restoreState->setEnabled($wasEnabled);
            }
            $restoreManager->flush();
        }
    }

    private function createGame(EntityManagerInterface $entityManager, string $slug): Game
    {
        $game = (new Game())
            ->setName('Widget game '.$slug)
            ->setSlug($slug)
            ->setEnabled(true);
        $entityManager->persist($game);

        return $game;
    }

    private function createCompetition(
        EntityManagerInterface $entityManager,
        Game $game,
        string $name,
        string $slugSuffix,
        bool $public,
        bool $includeUnconfirmedAndInactive = false,
    ): Competition {
        $userA = $this->user('alpha-'.$slugSuffix);
        $userB = $this->user('beta-'.$slugSuffix);
        $competition = (new Competition())
            ->setGame($game)
            ->setCreatedBy($userA)
            ->setName($name)
            ->setSlug('competition-'.str_replace('_', '-', $slugSuffix))
            ->setStartsAt(new \DateTimeImmutable('9999-12-31 00:00:00 UTC'));

        if (!$public) {
            $competition->setVisibility(Competition::VISIBILITY_PRIVATE);
        }

        $competition->open()->start();

        $participantA = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($userA)
            ->setName('Alpha '.$slugSuffix);
        $participantB = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($userB)
            ->setName('Beta '.$slugSuffix);
        $participantA->checkIn();
        $participantB->checkIn();

        $confirmed = (new CompetitionMatch())
            ->setCompetition($competition)
            ->setRoundNumber(1)
            ->setSequence(1)
            ->setParticipants($participantA, $participantB)
            ->markReady();
        $confirmed->submitResult($participantA, 2, 1, $userA);
        $confirmed->confirmResult($participantB);

        $entities = [$userA, $userB, $competition, $participantA, $participantB, $confirmed];
        if ($includeUnconfirmedAndInactive) {
            $unconfirmed = (new CompetitionMatch())
                ->setCompetition($competition)
                ->setRoundNumber(2)
                ->setSequence(1)
                ->setParticipants($participantA, $participantB)
                ->markReady();
            $unconfirmed->submitResult($participantA, 99, 0, $userA);

            $userC = $this->user('gamma-'.$slugSuffix);
            $participantC = (new CompetitionParticipant())
                ->setCompetition($competition)
                ->setCaptain($userC)
                ->setName('Gamma '.$slugSuffix);
            $participantC->checkIn();
            $inactiveResult = (new CompetitionMatch())
                ->setCompetition($competition)
                ->setRoundNumber(3)
                ->setSequence(1)
                ->setParticipants($participantA, $participantC)
                ->markReady();
            $inactiveResult->submitResult($participantA, 50, 0, $userA);
            $inactiveResult->confirmResult($participantC);
            $participantC->withdraw('Left the competition');

            $entities = [...$entities, $userC, $participantC, $unconfirmed, $inactiveResult];
        }

        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return $competition;
    }

    private function user(string $name): User
    {
        return (new User())
            ->setEmail($name.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName($name)
            ->verifyEmail();
    }
}
