<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCompetitionMatchCalendarTest extends WebTestCase
{
    public function testOnlyPublicScheduledMatchesAppearAndFeedIsValid(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previous = $this->setGaming($client, true);
        $entities = [];

        try {
            $em = $this->em($client);
            $suffix = bin2hex(random_bytes(5));
            $game = (new Game())->setName('Match Game '.$suffix)->setSlug('match-game-'.$suffix);
            $otherGame = (new Game())->setName('Other Game '.$suffix)->setSlug('other-match-game-'.$suffix);
            $disabledGame = (new Game())->setName('Disabled Game '.$suffix)->setSlug('disabled-match-game-'.$suffix);
            foreach ([$game, $otherGame, $disabledGame] as $item) {
                $em->persist($item);
                $entities[] = $item;
            }
            $em->flush();

            $date = new \DateTimeImmutable('+5 days', new \DateTimeZone('UTC'));
            $visible = $this->competition($game, "Visible, Cup;\nInjected ".$suffix, 'match-visible-'.$suffix);
            $private = $this->competition($game, 'Private Canary '.$suffix, 'match-private-'.$suffix)->setVisibility(Competition::VISIBILITY_PRIVATE);
            $draft = (new Competition())->setGame($game)->setName('Draft Canary '.$suffix)->setSlug('match-draft-'.$suffix);
            $disabled = $this->competition($disabledGame, 'Disabled Canary '.$suffix, 'match-disabled-'.$suffix);
            $other = $this->competition($otherGame, 'Other Canary '.$suffix, 'match-other-'.$suffix);
            $disabledGame->setEnabled(false);
            foreach ([$visible, $private, $draft, $disabled, $other] as $item) {
                $em->persist($item);
                $entities[] = $item;
            }
            $em->flush();

            $match = (new CompetitionMatch())->setCompetition($visible)->setScheduledAt($date);
            $hidden = (new CompetitionMatch())->setCompetition($private)->setScheduledAt($date);
            $draftMatch = (new CompetitionMatch())->setCompetition($draft)->setScheduledAt($date);
            $disabledMatch = (new CompetitionMatch())->setCompetition($disabled)->setScheduledAt($date);
            $otherMatch = (new CompetitionMatch())->setCompetition($other)->setScheduledAt($date);
            $unscheduled = (new CompetitionMatch())->setCompetition($visible)->setSequence(2);
            $past = (new CompetitionMatch())->setCompetition($visible)->setSequence(3)->setScheduledAt($date->modify('-10 days'));
            foreach ([$match, $hidden, $draftMatch, $disabledMatch, $otherMatch, $unscheduled, $past] as $item) {
                $em->persist($item);
                $entities[] = $item;
            }
            $em->flush();

            $client->request('GET', '/competitions/matches?game='.$game->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Visible, Cup;');
            foreach (['Private Canary', 'Draft Canary', 'Disabled Canary', 'Other Canary'] as $hiddenName) {
                self::assertSelectorTextNotContains('body', $hiddenName.' '.$suffix);
            }
            self::assertSelectorCount(1, '.guild-card');

            $client->request('GET', '/competitions/matches.ics?game='.$game->getSlug());
            self::assertResponseIsSuccessful();
            $body = (string) $client->getResponse()->getContent();
            self::assertStringStartsWith('text/calendar', (string) $client->getResponse()->headers->get('content-type'));
            self::assertStringContainsString('UID:competition-match-'.$match->getId().'@gaming-cms', $body);
            self::assertStringContainsString('DTSTART:'.$date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z'), $body);
            self::assertStringContainsString('SUMMARY:Spiel: Visible\\, Cup\\;\\nInjected '.$suffix, $body);
            self::assertStringNotContainsString('Private Canary', $body);
            self::assertStringNotContainsString('Other Canary', $body);
            self::assertSame(1, substr_count($body, "BEGIN:VEVENT\r\n"));
            foreach (explode("\r\n", $body) as $line) {
                self::assertLessThanOrEqual(75, strlen($line));
            }
            self::assertTrue(mb_check_encoding($body, 'UTF-8'));
        } finally {
            $this->remove($client, $entities);
            $this->restoreGaming($client, $previous);
        }
    }

    public function testInvalidFiltersAndDisabledGamingFailClosed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previous = $this->setGaming($client, true);

        try {
            foreach (['/competitions/matches?game%5B%5D=test', '/competitions/matches.ics?game=Unknown', '/competitions/matches?game=missing-game'] as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(404);
            }
            foreach (['/competitions/matches', '/competitions/matches.ics'] as $url) {
                $client->request('POST', $url);
                self::assertResponseStatusCodeSame(405);
            }
            $this->setGaming($client, false);
            foreach (['/competitions/matches', '/competitions/matches.ics'] as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            $this->restoreGaming($client, $previous);
        }
    }

    private function competition(Game $game, string $name, string $slug): Competition
    {
        return (new Competition())->setGame($game)->setName($name)->setSlug($slug)->open();
    }

    private function setGaming(KernelBrowser $client, bool $enabled): ?bool
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        $previous = $state?->isEnabled();
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previous;
    }

    private function restoreGaming(KernelBrowser $client, ?bool $previous): void
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        if ($state instanceof CmsModuleState) {
            if ($previous === null) {
                $em->remove($state);
            } else {
                $state->setEnabled($previous);
            }
            $em->flush();
            $em->clear();
        }
    }

    /** @param list<object> $entities */
    private function remove(KernelBrowser $client, array $entities): void
    {
        $em = $this->em($client);
        foreach (array_reverse($entities) as $entity) {
            $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
            if ($ids === [] || in_array(null, $ids, true)) {
                continue;
            }
            $id = count($ids) === 1 ? array_values($ids)[0] : $ids;
            $managed = $em->find($entity::class, $id);
            if ($managed !== null) {
                $em->remove($managed);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
