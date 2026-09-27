<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompetitionCalendarControllerTest extends WebTestCase
{
    public function testCalendarAndFeedShowOnlyUpcomingPublicOpenCompetitions(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, true);
        $entities = [];

        try {
            $entityManager = $this->entityManager($client);
            $suffix = bin2hex(random_bytes(5));
            $game = (new Game())->setName('Calendar Game '.$suffix)->setSlug('calendar-game-'.$suffix);
            $disabledGame = (new Game())->setName('Disabled Calendar Game '.$suffix)->setSlug('disabled-game-'.$suffix);
            foreach ([$game, $disabledGame] as $item) {
                $entityManager->persist($item);
                $entities[] = $item;
            }
            $entityManager->flush();

            $startsAt = new \DateTimeImmutable('+3 days', new \DateTimeZone('Europe/Berlin'));
            $public = $this->openCompetition($game, 'Visible Cup '.$suffix, 'visible-cup-'.$suffix, $startsAt);
            $private = $this->openCompetition(
                $game,
                'Private Cup Canary '.$suffix,
                'private-cup-'.$suffix,
                $startsAt->modify('+1 day'),
                Competition::VISIBILITY_PRIVATE,
            );
            $draft = (new Competition())
                ->setGame($game)
                ->setName('Draft Cup Canary '.$suffix)
                ->setSlug('draft-cup-'.$suffix)
                ->setStartsAt($startsAt->modify('+2 days'));
            $disabled = $this->openCompetition(
                $disabledGame,
                'Disabled Game Cup Canary '.$suffix,
                'disabled-cup-'.$suffix,
                $startsAt->modify('+3 days'),
            );
            $disabledGame->setEnabled(false);
            $completed = $this->openCompetition(
                $game,
                'Completed Cup Canary '.$suffix,
                'completed-cup-'.$suffix,
                $startsAt->modify('+4 days'),
            );
            $completed->start()->complete();
            $archived = $this->openCompetition(
                $game,
                'Archived Cup Canary '.$suffix,
                'archived-cup-'.$suffix,
                $startsAt->modify('+5 days'),
            );
            $archived->archive();
            $past = $this->openCompetition(
                $game,
                'Past Cup Canary '.$suffix,
                'past-cup-'.$suffix,
                new \DateTimeImmutable('-2 days'),
            );

            $captain = (new User())
                ->setEmail('private-captain-'.$suffix.'@example.test')
                ->setDisplayName('Private Captain Canary '.$suffix);
            $public->setCreatedBy($captain);
            $participant = (new CompetitionParticipant())
                ->setCompetition($public)
                ->setCaptain($captain)
                ->setName('Private Roster Canary '.$suffix);

            $entities[] = $captain;
            $entityManager->persist($captain);
            foreach ([$public, $private, $draft, $disabled, $completed, $archived, $past] as $competition) {
                $entities[] = $competition;
                $entityManager->persist($competition);
            }
            $entities[] = $participant;
            $entityManager->persist($participant);
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/competitions/calendar"]');
            self::assertSelectorTextContains('body', 'Wettbewerbskalender entdecken');

            $client->request('GET', '/competitions/calendar');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Visible Cup '.$suffix);
            foreach (['Private Cup Canary', 'Draft Cup Canary', 'Disabled Game Cup Canary', 'Completed Cup Canary', 'Archived Cup Canary', 'Past Cup Canary'] as $hiddenTitle) {
                self::assertSelectorTextNotContains('body', $hiddenTitle.' '.$suffix);
            }

            $client->request('GET', '/competitions/calendar.ics');
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('text/calendar', (string) $client->getResponse()->headers->get('content-type'));
            $body = $this->responseContent($client);
            self::assertStringContainsString('SUMMARY:Visible Cup '.$suffix, $body);
            self::assertStringContainsString('UID:competition-'.$public->getId().'@gaming-cms', $body);
            self::assertStringNotContainsString('Private Cup Canary', $body);
            self::assertStringNotContainsString('Draft Cup Canary', $body);
            self::assertStringNotContainsString('Disabled Game Cup Canary', $body);
            self::assertStringNotContainsString('Completed Cup Canary', $body);
            self::assertStringNotContainsString('Archived Cup Canary', $body);
            self::assertStringNotContainsString('Past Cup Canary', $body);
            self::assertStringNotContainsString('private-captain-'.$suffix.'@example.test', $body);
            self::assertStringNotContainsString('Private Captain Canary '.$suffix, $body);
            self::assertStringNotContainsString('Private Roster Canary '.$suffix, $body);
        } finally {
            $this->removeEntities($client, $entities);
            $this->restoreGamingState($client, $previousGamingState);
        }
    }

    public function testGameFilterKeepsTheLandingPageAndFeedScopedAndEscapesIcalendarText(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, true);
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        $entities = [];

        try {
            $entityManager = $this->entityManager($client);
            $suffix = bin2hex(random_bytes(5));
            $game = (new Game())->setName('Calendar Game '.$suffix)->setSlug('calendar-game-'.$suffix);
            $otherGame = (new Game())->setName('Other Calendar Game '.$suffix)->setSlug('other-calendar-game-'.$suffix);
            foreach ([$game, $otherGame] as $item) {
                $entityManager->persist($item);
                $entities[] = $item;
            }
            $entityManager->flush();

            $startsAt = new \DateTimeImmutable('+5 days', new \DateTimeZone('Europe/Berlin'));
            $name = "Finale, R&D;\r\nBEGIN:VEVENT ".str_repeat('Ö', 55);
            $competition = $this->openCompetition($game, $name, 'calendar-finals-'.$suffix, $startsAt);
            $competition->setDescription("Schedule,\r\nRound; 2\\ review ".str_repeat('ä', 55));
            $otherCompetition = $this->openCompetition(
                $otherGame,
                'Other Game Cup '.$suffix,
                'other-game-cup-'.$suffix,
                $startsAt,
            );
            foreach ([$competition, $otherCompetition] as $item) {
                $entityManager->persist($item);
                $entities[] = $item;
            }
            $entityManager->flush();

            $client->request('GET', '/competitions/calendar?game='.$game->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Finale, R&D;');
            self::assertSelectorTextNotContains('body', 'Other Game Cup '.$suffix);
            self::assertSelectorExists('a[href*="/competitions/calendar.ics?game='.$game->getSlug().'"]');

            $client->request('GET', '/competitions/calendar.ics?game='.$game->getSlug());
            self::assertResponseIsSuccessful();
            $body = $this->responseContent($client);
            self::assertStringContainsString('UID:competition-'.$competition->getId().'@gaming-cms', $body);
            self::assertStringNotContainsString('Other Game Cup '.$suffix, $body);
            self::assertStringContainsString('SUMMARY:Finale\\, R&D\\;\\nBEGIN:VEVENT', $body);
            self::assertStringContainsString('DTSTART:'.$startsAt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z'), $body);
            self::assertStringContainsString('DTEND:'.$startsAt->modify('+2 hours')->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z'), $body);
            self::assertStringContainsString('/competitions/'.$competition->getId(), $body);

            $physicalLines = explode("\r\n", $body);
            foreach ($physicalLines as $line) {
                if ($line !== '') {
                    self::assertLessThanOrEqual(75, strlen($line));
                }
            }
            $unfolded = preg_replace("/\r\n /", '', $body);
            if (!is_string($unfolded)) {
                self::fail('The calendar feed must contain valid foldable text.');
            }
            self::assertTrue(mb_check_encoding($unfolded, 'UTF-8'));
            self::assertSame(1, substr_count($body, "BEGIN:VEVENT\r\n"));
            self::assertStringContainsString('DESCRIPTION:Calendar Game '.$suffix, $body);
        } finally {
            $this->removeEntities($client, $entities);
            $this->restoreGamingState($client, $previousGamingState);
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testMalformedUnknownDisabledAndArrayGameFiltersFailClosedAndWritesAreRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, true);
        $entities = [];

        try {
            $entityManager = $this->entityManager($client);
            $suffix = bin2hex(random_bytes(5));
            $enabledGame = (new Game())->setName('Enabled Filter Game '.$suffix)->setSlug('enabled-filter-'.$suffix);
            $disabledGame = (new Game())->setName('Disabled Filter Game '.$suffix)->setSlug('disabled-filter-'.$suffix)->setEnabled(false);
            foreach ([$enabledGame, $disabledGame] as $game) {
                $entityManager->persist($game);
                $entities[] = $game;
            }
            $entityManager->flush();

            $client->request('GET', '/competitions/calendar?game='.$enabledGame->getSlug());
            self::assertResponseIsSuccessful();

            foreach ([
                '/competitions/calendar?game=unknown-'.$suffix,
                '/competitions/calendar?game='.$disabledGame->getSlug(),
                '/competitions/calendar?game=BadSlug',
                '/competitions/calendar?game%5B%5D=enabled-filter-'.$suffix,
                '/competitions/calendar?game='.str_repeat('a', 141),
            ] as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(404);
            }

            $client->request('POST', '/competitions/calendar');
            self::assertResponseStatusCodeSame(405);
            $client->request('POST', '/competitions/calendar.ics');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->removeEntities($client, $entities);
            $this->restoreGamingState($client, $previousGamingState);
        }
    }

    public function testBothRoutesFailClosedWhenGamingIsDisabledAndHomeLinkIsHidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, false);

        try {
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/competitions/calendar"]');

            $client->request('GET', '/competitions/calendar');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/competitions/calendar.ics');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreGamingState($client, $previousGamingState);
        }
    }

    private function openCompetition(
        Game $game,
        string $name,
        string $slug,
        \DateTimeImmutable $startsAt,
        string $visibility = Competition::VISIBILITY_PUBLIC,
    ): Competition {
        return (new Competition())
            ->setGame($game)
            ->setName($name)
            ->setSlug($slug)
            ->setVisibility($visibility)
            ->setStartsAt($startsAt)
            ->setEndsAt($startsAt->modify('+2 hours'))
            ->open();
    }

    private function setGamingEnabled(KernelBrowser $client, bool $enabled): ?bool
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        $previous = $state?->isEnabled();
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())
                ->setModuleKey('gaming')
                ->updateVersion('1.0.0');
        }
        $state->setEnabled($enabled);
        $entityManager->persist($state);
        $entityManager->flush();

        return $previous;
    }

    private function restoreGamingState(KernelBrowser $client, ?bool $previous): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        if (!$state instanceof CmsModuleState) {
            return;
        }
        if ($previous === null) {
            $entityManager->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @param list<object> $entities
     */
    private function removeEntities(KernelBrowser $client, array $entities): void
    {
        $entityManager = $this->entityManager($client);
        foreach (array_reverse($entities) as $entity) {
            $entityManager->remove($entity);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function responseContent(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        if ($content === false) {
            throw new \RuntimeException('Expected a response body.');
        }

        return $content;
    }
}
