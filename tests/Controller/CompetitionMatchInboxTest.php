<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompetitionMatchInboxTest extends WebTestCase
{
    public function testCaptainSeesOnlyOwnMatchesWithPrivateResponseHeaders(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $module = $em->find(CmsModuleState::class, 'gaming');
        $previous = $module?->isEnabled();
        if (!$module instanceof CmsModuleState) {
            $module = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('test');
            $em->persist($module);
        }
        $module->setEnabled(true);
        $suffix = bin2hex(random_bytes(6));
        $users = [];
        $participants = [];
        $matches = [];
        $game = (new Game())->setName('Inbox '.$suffix)->setSlug('inbox-'.$suffix);
        $competition = (new Competition())->setGame($game)->setName('Inbox Cup '.$suffix)
            ->setSlug('inbox-cup-'.$suffix)->setStartsAt(new \DateTimeImmutable('2200-01-01 UTC'));
        $competition->open()->start();
        $em->persist($game);
        $em->persist($competition);

        for ($i = 0; $i < 4; ++$i) {
            $user = (new User())->setEmail('inbox-'.$suffix.'-'.$i.'@example.test')
                ->setDisplayName('Inbox player '.$i)->verifyEmail();
            $participant = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($user)
                ->setName('Inbox team '.$i.' '.$suffix);
            $participant->checkIn();
            $em->persist($user);
            $em->persist($participant);
            $users[] = $user;
            $participants[] = $participant;
        }
        for ($i = 0; $i < 4; $i += 2) {
            $match = (new CompetitionMatch())->setCompetition($competition)->setRoundNumber(1)
                ->setSequence(intdiv($i, 2) + 1)->setParticipants($participants[$i], $participants[$i + 1])
                ->markReady();
            $em->persist($match);
            $matches[] = $match;
        }
        $em->flush();

        try {
            $client->request('GET', '/account/competitions/matches');
            self::assertResponseRedirects('/login');

            $client->loginUser($users[0]);
            $client->request('GET', '/account/competitions/matches');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Inbox team 0 '.$suffix);
            self::assertSelectorTextNotContains('main', 'Inbox team 2 '.$suffix);
            self::assertSelectorTextNotContains('main', 'Inbox team 3 '.$suffix);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));

            $current = $client->getContainer()->get(EntityManagerInterface::class)->find(CmsModuleState::class, 'gaming');
            self::assertInstanceOf(CmsModuleState::class, $current);
            $current->setEnabled(false);
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
            $client->request('GET', '/account/competitions/matches');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $fresh = $client->getContainer()->get(EntityManagerInterface::class);
            foreach ($matches as $match) {
                $managed = $fresh->find(CompetitionMatch::class, $match->getId());
                if ($managed !== null) { $fresh->remove($managed); }
            }
            foreach ($participants as $participant) {
                $managed = $fresh->find(CompetitionParticipant::class, $participant->getId());
                if ($managed !== null) { $fresh->remove($managed); }
            }
            $managed = $fresh->find(Competition::class, $competition->getId());
            if ($managed !== null) { $fresh->remove($managed); }
            $managed = $fresh->find(Game::class, $game->getId());
            if ($managed !== null) { $fresh->remove($managed); }
            foreach ($users as $user) {
                $managed = $fresh->find(User::class, $user->getId());
                if ($managed !== null) { $fresh->remove($managed); }
            }
            $module = $fresh->find(CmsModuleState::class, 'gaming');
            if ($module instanceof CmsModuleState) {
                if ($previous === null) { $fresh->remove($module); }
                else { $module->setEnabled($previous); }
            }
            $fresh->flush();
        }
    }
}
