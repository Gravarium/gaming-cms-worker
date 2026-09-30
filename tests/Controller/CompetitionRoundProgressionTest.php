<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\CompetitionProgression\SingleEliminationProgression;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompetitionRoundProgressionTest extends WebTestCase
{
    public function testConfirmedWinnersAdvanceOnceAndFinalCompletesCompetition(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        [$competition, $participants, $matches, $game, $users] = $this->fixtures($em, 4);

        try {
            $service = $client->getContainer()->get(SingleEliminationProgression::class);
            $this->confirm($matches[0], $participants[0], $participants[1], $users[0]);
            $this->confirm($matches[1], $participants[2], $participants[3], $users[2]);
            $em->flush();
            self::assertSame(2, $service->advance($competition));
            $all = $em->getRepository(CompetitionMatch::class)->findBy(['competition' => $competition]);
            self::assertCount(3, $all);
            $final = array_values(array_filter($all, static fn (CompetitionMatch $match): bool => $match->getRoundNumber() === 2))[0];
            self::assertSame($participants[0], $final->getParticipantA());
            self::assertSame($participants[2], $final->getParticipantB());

            $this->confirm($final, $participants[0], $participants[2], $users[0]);
            $em->flush();
            self::assertNull($service->advance($competition));
            self::assertSame(Competition::STATUS_COMPLETED, $competition->getStatus());
            self::assertCount(3, $em->getRepository(CompetitionMatch::class)->findBy(['competition' => $competition]));
        } finally {
            $this->removeFixtures($em, $competition, $game, $users);
        }
    }

    public function testRouteRequiresManagerPermissionAndCsrf(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        [$competition, , , $game, $users] = $this->fixtures($em, 2);
        $manager = (new User())->setEmail('round-manager-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Round manager')->setPermissions(['CMS_GAMING_MANAGE'])->verifyEmail();
        $em->persist($manager);
        $em->flush();

        try {
            $url = '/admin/gaming/competitions/'.$competition->getId().'/advance';
            $client->loginUser($users[0]);
            $client->request('POST', $url, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);

            $client->loginUser($manager);
            $client->request('POST', $url, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            self::assertCount(1, $this->em($client)->getRepository(CompetitionMatch::class)->findBy(['competition' => $competition]));
        } finally {
            $this->removeFixtures($this->em($client), $competition, $game, [...$users, $manager]);
        }
    }

    public function testIncompleteRoundDoesNotAdvanceWithValidManagerToken(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        [$competition, , , $game, $users] = $this->fixtures($em, 4);
        $manager = (new User())->setEmail('round-incomplete-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Round manager')->setPermissions(['CMS_GAMING_MANAGE'])->verifyEmail();
        $em->persist($manager);
        $em->flush();

        try {
            $client->loginUser($manager);
            $crawler = $client->request('GET', '/admin/gaming/competitions');
            self::assertResponseIsSuccessful();
            $token = (string) $crawler->filter('form[action="/admin/gaming/competitions/'.$competition->getId().'/advance"] input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/gaming/competitions/'.$competition->getId().'/advance', ['_token' => $token]);
            self::assertResponseRedirects('/admin/gaming/competitions');

            // A rejected transaction closes its manager; read back through a fresh one.
            $registry = $client->getContainer()->get(ManagerRegistry::class);
            $registry->resetManager();
            $fresh = $this->em($client);
            $managed = $fresh->find(Competition::class, $competition->getId());
            self::assertInstanceOf(Competition::class, $managed);
            self::assertSame(Competition::STATUS_IN_PROGRESS, $managed->getStatus());
            self::assertCount(2, $fresh->getRepository(CompetitionMatch::class)->findBy(['competition' => $managed]));
        } finally {
            $registry = $client->getContainer()->get(ManagerRegistry::class);
            $registry->resetManager();
            $this->removeFixtures($this->em($client), $competition, $game, [...$users, $manager]);
        }
    }

    /** @return array{Competition, list<CompetitionParticipant>, list<CompetitionMatch>, Game, list<User>} */
    private function fixtures(EntityManagerInterface $em, int $count): array
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())->setName('Round game '.$suffix)->setSlug('round-game-'.$suffix);
        $competition = (new Competition())->setGame($game)->setName('Round Cup '.$suffix)
            ->setSlug('round-cup-'.$suffix)->setStartsAt(new \DateTimeImmutable('2200-01-01 UTC'));
        $competition->open()->start();
        $em->persist($game);
        $em->persist($competition);
        $users = $participants = $matches = [];
        for ($i = 0; $i < $count; ++$i) {
            $user = (new User())->setEmail('round-'.$suffix.'-'.$i.'@example.test')->setDisplayName('Round player '.$i)->verifyEmail();
            $participant = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($user)->setName('Player '.$i);
            $participant->checkIn();
            $em->persist($user);
            $em->persist($participant);
            $users[] = $user;
            $participants[] = $participant;
        }
        for ($i = 0; $i < $count; $i += 2) {
            $match = (new CompetitionMatch())->setCompetition($competition)->setRoundNumber(1)
                ->setBracket(CompetitionMatch::BRACKET_WINNERS)->setSequence(intdiv($i, 2) + 1)
                ->setParticipants($participants[$i], $participants[$i + 1])->markReady();
            $em->persist($match);
            $matches[] = $match;
        }
        $em->flush();
        return [$competition, $participants, $matches, $game, $users];
    }

    private function confirm(CompetitionMatch $match, CompetitionParticipant $first, CompetitionParticipant $second, User $actor): void
    {
        $match->submitResult($first, 2, 1, $actor)->confirmResult($second);
    }

    private function em(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<User> $users */
    private function removeFixtures(EntityManagerInterface $em, Competition $competition, Game $game, array $users): void
    {
        if (!$em->isOpen()) { return; }
        $managedCompetition = $em->find(Competition::class, $competition->getId());
        if ($managedCompetition instanceof Competition) {
            foreach ($em->getRepository(CompetitionMatch::class)->findBy(['competition' => $managedCompetition]) as $match) { $em->remove($match); }
            foreach ($em->getRepository(CompetitionParticipant::class)->findBy(['competition' => $managedCompetition]) as $participant) { $em->remove($participant); }
            $em->remove($managedCompetition);
        }
        $managedGame = $em->find(Game::class, $game->getId());
        if ($managedGame instanceof Game) { $em->remove($managedGame); }
        foreach ($users as $user) {
            $managedUser = $em->find(User::class, $user->getId());
            if ($managedUser instanceof User) { $em->remove($managedUser); }
        }
        $em->flush();
    }
}
