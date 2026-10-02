<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCompetitionParticipantTest extends WebTestCase
{
    /** @var list<int> */
    private array $competitionIds = [];
    /** @var list<int> */
    private array $participantIds = [];
    /** @var list<int> */
    private array $matchIds = [];
    /** @var list<int> */
    private array $gameIds = [];
    /** @var list<int> */
    private array $userIds = [];
    private ?KernelBrowser $fixtureClient = null;

    public function testManagerCanSeedWithdrawAndDisqualifyWithinOneCompetition(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['manager']);
        $base = '/admin/gaming/competitions/'.$fixture['competitionId'].'/participants';

        $crawler = $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
        self::assertStringContainsString('no-store', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertCount(3, $crawler->filter('tr[data-participant-id]'));

        $firstId = $fixture['participantIds'][0];
        $secondId = $fixture['participantIds'][1];
        $thirdId = $fixture['participantIds'][2];
        $seedPath = $base.'/'.$firstId.'/seed';
        $client->request('POST', $seedPath, [
            '_token' => $this->token($crawler, $seedPath),
            'seed' => '2',
        ]);
        self::assertResponseRedirects($base);
        self::assertSame(2, $this->participant($client, $firstId)->getSeed());

        $crawler = $client->request('GET', $base);
        $withdrawPath = $base.'/'.$secondId.'/withdraw';
        $client->request('POST', $withdrawPath, [
            '_token' => $this->token($crawler, $withdrawPath),
            'reason' => 'Anmeldung auf Wunsch beendet',
        ]);
        self::assertResponseRedirects($base);
        self::assertSame(CompetitionParticipant::STATUS_WITHDRAWN, $this->participant($client, $secondId)->getStatus());
        self::assertSame('Anmeldung auf Wunsch beendet', $this->participant($client, $secondId)->getStatusReason());

        $crawler = $client->request('GET', $base);
        $disqualifyPath = $base.'/'.$thirdId.'/disqualify';
        $client->request('POST', $disqualifyPath, [
            '_token' => $this->token($crawler, $disqualifyPath),
            'reason' => 'Regelverstoß',
        ]);
        self::assertResponseRedirects($base);
        self::assertSame(CompetitionParticipant::STATUS_DISQUALIFIED, $this->participant($client, $thirdId)->getStatus());
        self::assertSame('Regelverstoß', $this->participant($client, $thirdId)->getStatusReason());
        self::assertSame(2, $this->participant($client, $firstId)->getSeed());
    }

    public function testMutationsRejectCsrfDuplicatesCrossCompetitionAndTerminalReplay(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['manager']);
        $base = '/admin/gaming/competitions/'.$fixture['competitionId'].'/participants';
        [$firstId, $secondId] = $fixture['participantIds'];

        $client->request('POST', $base.'/'.$firstId.'/seed', ['_token' => 'forged', 'seed' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->participant($client, $firstId)->getSeed());

        $crawler = $client->request('GET', $base);
        $firstSeedPath = $base.'/'.$firstId.'/seed';
        $client->request('POST', $firstSeedPath, ['_token' => $this->token($crawler, $firstSeedPath), 'seed' => '1']);
        self::assertResponseRedirects($base);

        $crawler = $client->request('GET', $base);
        $secondSeedPath = $base.'/'.$secondId.'/seed';
        $client->request('POST', $secondSeedPath, ['_token' => $this->token($crawler, $secondSeedPath), 'seed' => '1']);
        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->participant($client, $secondId)->getSeed());

        $foreignId = $fixture['foreignParticipantId'];
        $foreignBase = '/admin/gaming/competitions/'.$fixture['otherCompetitionId'].'/participants';
        $foreignCrawler = $client->request('GET', $foreignBase);
        $foreignPath = $foreignBase.'/'.$foreignId.'/withdraw';
        $foreignToken = $this->token($foreignCrawler, $foreignPath);
        $client->request('POST', $base.'/'.$foreignId.'/withdraw', ['_token' => $foreignToken]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(CompetitionParticipant::STATUS_REGISTERED, $this->participant($client, $foreignId)->getStatus());

        $crawler = $client->request('GET', $base);
        $withdrawPath = $base.'/'.$secondId.'/withdraw';
        $withdrawToken = $this->token($crawler, $withdrawPath);
        $client->request('POST', $withdrawPath, ['_token' => $withdrawToken]);
        self::assertResponseRedirects($base);
        $client->request('POST', $withdrawPath, ['_token' => $withdrawToken]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(CompetitionParticipant::STATUS_WITHDRAWN, $this->participant($client, $secondId)->getStatus());
    }

    public function testMissingOrOversizedReasonAndExistingPairingDoNotMutate(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['manager']);
        $base = '/admin/gaming/competitions/'.$fixture['competitionId'].'/participants';
        [$firstId, $secondId] = $fixture['participantIds'];

        $crawler = $client->request('GET', $base);
        $disqualifyPath = $base.'/'.$firstId.'/disqualify';
        $client->request('POST', $disqualifyPath, ['_token' => $this->token($crawler, $disqualifyPath), 'reason' => '']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(CompetitionParticipant::STATUS_REGISTERED, $this->participant($client, $firstId)->getStatus());

        $crawler = $client->request('GET', $base);
        $withdrawPath = $base.'/'.$firstId.'/withdraw';
        $client->request('POST', $withdrawPath, ['_token' => $this->token($crawler, $withdrawPath), 'reason' => str_repeat('x', 501)]);
        self::assertResponseStatusCodeSame(400);

        $em = $this->em($client);
        $competition = $em->find(Competition::class, $fixture['competitionId']);
        $first = $em->find(CompetitionParticipant::class, $firstId);
        $second = $em->find(CompetitionParticipant::class, $secondId);
        self::assertInstanceOf(Competition::class, $competition);
        self::assertInstanceOf(CompetitionParticipant::class, $first);
        self::assertInstanceOf(CompetitionParticipant::class, $second);
        $match = (new CompetitionMatch())
            ->setCompetition($competition)
            ->setRoundNumber(1)
            ->setBracket('winners')
            ->setSequence(1)
            ->setParticipants($first, $second);
        $match->markReady();
        $em->persist($match);
        $em->flush();
        $this->matchIds[] = (int) $match->getId();

        $crawler = $client->request('GET', $base);
        self::assertSelectorTextContains('[role="status"]', 'schreibgeschützt');
        self::assertSelectorNotExists('form[action$="/'.$firstId.'/seed"]');
        $client->request('POST', $base.'/'.$firstId.'/seed', ['_token' => 'forged', 'seed' => '3']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->participant($client, $firstId)->getSeed());
    }

    public function testPermissionAndGamingModuleGateProtectTheBoard(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $base = '/admin/gaming/competitions/'.$fixture['competitionId'].'/participants';
        $client->loginUser($fixture['plainUser']);
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(403);

        $client->restart();
        $client->loginUser($fixture['manager']);
        $em = $this->em($client);
        $existing = $em->find(CmsModuleState::class, 'gaming');
        $wasEnabled = $existing?->isEnabled() ?? true;
        $client->getContainer()->get(CmsModuleManager::class)->setEnabled('gaming', false);
        try {
            $client->request('GET', $base);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $state = $em->find(CmsModuleState::class, 'gaming');
            if ($existing === null && $state instanceof CmsModuleState) {
                $em->remove($state);
            } elseif ($state instanceof CmsModuleState) {
                $state->setEnabled($wasEnabled);
            }
            $em->flush();
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixtureClient !== null) {
                $em = $this->em($this->fixtureClient);
                $em->clear();
                foreach ([[$this->matchIds, CompetitionMatch::class], [$this->participantIds, CompetitionParticipant::class], [$this->competitionIds, Competition::class], [$this->gameIds, Game::class], [$this->userIds, User::class]] as [$ids, $class]) {
                    foreach (array_reverse($ids) as $id) {
                        $entity = $em->find($class, $id);
                        if ($entity !== null) {
                            $em->remove($entity);
                        }
                    }
                    $em->flush();
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array{manager: User, plainUser: User, competitionId: int, otherCompetitionId: int, participantIds: list<int>, foreignParticipantId: int} */
    private function fixture(KernelBrowser $client): array
    {
        $this->fixtureClient = $client;
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())->setName('Participant admin game '.$suffix)->setSlug('participant-admin-game-'.$suffix);
        $manager = (new User())->setEmail('participant-manager-'.$suffix.'@example.test')->setDisplayName('Participant manager')->setPermissions([CmsPermission::ACCESS, CmsPermission::GAMING])->verifyEmail();
        $plain = (new User())->setEmail('participant-plain-'.$suffix.'@example.test')->setDisplayName('Participant plain')->setPermissions([CmsPermission::ACCESS])->verifyEmail();
        $captains = [];
        for ($i = 0; $i < 4; ++$i) {
            $captains[] = (new User())->setEmail('participant-captain-'.$i.'-'.$suffix.'@example.test')->setDisplayName('Captain '.$i)->setPermissions([])->verifyEmail();
        }
        $competition = (new Competition())->setGame($game)->setName('Participant Cup '.$suffix)->setSlug('participant-cup-'.$suffix)->open();
        $other = (new Competition())->setGame($game)->setName('Other Participant Cup '.$suffix)->setSlug('other-participant-cup-'.$suffix)->open();
        foreach ([$game, $manager, $plain, ...$captains, $competition, $other] as $entity) {
            $em->persist($entity);
        }
        $participants = [];
        for ($i = 0; $i < 3; ++$i) {
            $participants[] = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($captains[$i])->setName('Participant '.$i.' '.$suffix)->setRosterUserIds([]);
        }
        $foreign = (new CompetitionParticipant())->setCompetition($other)->setCaptain($captains[3])->setName('Foreign '.$suffix)->setRosterUserIds([]);
        foreach ([...$participants, $foreign] as $participant) {
            $em->persist($participant);
        }
        $em->flush();

        $this->gameIds[] = (int) $game->getId();
        $this->competitionIds = [(int) $competition->getId(), (int) $other->getId()];
        $this->participantIds = array_map(static fn (CompetitionParticipant $participant): int => (int) $participant->getId(), [...$participants, $foreign]);
        $this->userIds = array_map(static fn (User $user): int => (int) $user->getId(), [$manager, $plain, ...$captains]);

        return [
            'manager' => $manager,
            'plainUser' => $plain,
            'competitionId' => (int) $competition->getId(),
            'otherCompetitionId' => (int) $other->getId(),
            'participantIds' => array_map(static fn (CompetitionParticipant $participant): int => (int) $participant->getId(), $participants),
            'foreignParticipantId' => (int) $foreign->getId(),
        ];
    }

    private function token(\Symfony\Component\DomCrawler\Crawler $crawler, string $action): string
    {
        return (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
    }

    private function participant(KernelBrowser $client, int $id): CompetitionParticipant
    {
        $this->em($client)->clear();
        $participant = $this->em($client)->find(CompetitionParticipant::class, $id);
        self::assertInstanceOf(CompetitionParticipant::class, $participant);

        return $participant;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        if ($entityManager->isOpen()) {
            return $entityManager;
        }

        $registry = $client->getContainer()->get(ManagerRegistry::class);
        $registry->resetManager();
        $entityManager = $registry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Doctrine did not reset the EntityManager.');
        }

        return $entityManager;
    }
}
