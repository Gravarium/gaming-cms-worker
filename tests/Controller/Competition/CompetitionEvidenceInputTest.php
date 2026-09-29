<?php

declare(strict_types=1);

namespace App\Tests\Controller\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionMatchEvidence;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CompetitionEvidenceInputTest extends WebTestCase
{
    public function testEvidenceLocatorAllowsTheColumnLimitAndRejectsLongerValues(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['user']);

        $prefix = 'https://example.test/';
        $locator = $prefix.str_repeat('a', 500 - mb_strlen($prefix, 'UTF-8'));
        self::assertSame(500, mb_strlen($locator, 'UTF-8'));

        $path = $this->evidencePath($fixture['competitionId'], $fixture['matchId']);
        $token = $this->csrfToken($client, $fixture['competitionId'], $fixture['matchId']);
        $client->request('POST', $path, [
            '_token' => $token,
            'participant' => (string) $fixture['participantId'],
            'locator' => $locator,
            'type' => CompetitionMatchEvidence::TYPE_URL,
        ]);

        self::assertResponseStatusCodeSame(302);
        $match = $this->match($client, $fixture['matchId']);
        $saved = $this->em($client)->getRepository(CompetitionMatchEvidence::class)->findOneBy(['match' => $match]);
        self::assertInstanceOf(CompetitionMatchEvidence::class, $saved);
        self::assertSame($locator, $saved->getLocator());

        $client->request('POST', $path, [
            '_token' => $token,
            'participant' => (string) $fixture['participantId'],
            'locator' => $locator.'a',
            'type' => CompetitionMatchEvidence::TYPE_URL,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->evidenceCount($client, $fixture['matchId']));
    }

    public function testEvidenceEntityRejectsLocatorAboveItsColumnLimit(): void
    {
        $prefix = 'https://example.test/';
        $locator = $prefix.str_repeat('a', 501 - mb_strlen($prefix, 'UTF-8'));

        $this->expectException(\InvalidArgumentException::class);
        (new CompetitionMatchEvidence())->setLocator($locator);
    }

    public function testNonParticipantCannotUseAnOversizedEvidenceLocator(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['outsider']);

        $client->request('POST', $this->evidencePath($fixture['competitionId'], $fixture['matchId']), [
            '_token' => $this->csrfToken($client, $fixture['competitionId'], $fixture['matchId']),
            'participant' => (string) $fixture['participantId'],
            'locator' => 'https://example.test/'.str_repeat('a', 480),
            'type' => CompetitionMatchEvidence::TYPE_URL,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->evidenceCount($client, $fixture['matchId']));
    }

    public function testInvalidCsrfTokenIsRejectedBeforeLocatorLengthValidation(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['user']);

        $client->request('POST', $this->evidencePath($fixture['competitionId'], $fixture['matchId']), [
            '_token' => 'invalid-token',
            'participant' => (string) $fixture['participantId'],
            'locator' => 'https://example.test/'.str_repeat('a', 480),
            'type' => CompetitionMatchEvidence::TYPE_URL,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->evidenceCount($client, $fixture['matchId']));
    }

    /**
     * @return array{
     *   competitionId: int,
     *   matchId: int,
     *   participantId: int,
     *   user: User,
     *   outsider: User
     * }
     */
    private function fixture(KernelBrowser $client): array
    {
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())
            ->setName('Evidence Game '.$suffix)
            ->setSlug('evidence-game-'.$suffix)
            ->setEnabled(true);
        $competition = (new Competition())
            ->setGame($game)
            ->setName('Evidence Cup '.$suffix)
            ->setSlug('evidence-cup-'.$suffix)
            ->open()
            ->start();
        $user = (new User())
            ->setEmail('evidence-owner-'.$suffix.'@example.test')
            ->setDisplayName('Evidence owner')
            ->verifyEmail();
        $opponent = (new User())
            ->setEmail('evidence-opponent-'.$suffix.'@example.test')
            ->setDisplayName('Evidence opponent')
            ->verifyEmail();
        $outsider = (new User())
            ->setEmail('evidence-outsider-'.$suffix.'@example.test')
            ->setDisplayName('Evidence outsider')
            ->verifyEmail();
        $participant = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($user)
            ->setName('Evidence owner')
            ->checkIn();
        $otherParticipant = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($opponent)
            ->setName('Evidence opponent')
            ->checkIn();
        $match = (new CompetitionMatch())
            ->setCompetition($competition)
            ->setParticipants($participant, $otherParticipant)
            ->markReady();

        foreach ([$game, $competition, $user, $opponent, $outsider, $participant, $otherParticipant, $match] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $competitionId = $competition->getId();
        $matchId = $match->getId();
        $participantId = $participant->getId();
        if ($competitionId === null || $matchId === null || $participantId === null) {
            throw new \LogicException('Competition evidence fixture did not receive database identifiers.');
        }

        return [
            'competitionId' => $competitionId,
            'matchId' => $matchId,
            'participantId' => $participantId,
            'user' => $user,
            'outsider' => $outsider,
        ];
    }

    private function evidencePath(int $competitionId, int $matchId): string
    {
        return '/competitions/'.$competitionId.'/match/'.$matchId.'/evidence';
    }

    private function csrfToken(KernelBrowser $client, int $competitionId, int $matchId): string
    {
        $client->request('GET', '/competitions/'.$competitionId);
        $request = $client->getRequest();
        if (!$request->hasSession()) {
            throw new \LogicException('Competition show request did not start a session.');
        }

        $requestStack = $client->getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        try {
            $token = $client->getContainer()
                ->get(CsrfTokenManagerInterface::class)
                ->getToken('competition-evidence-'.$matchId)
                ->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function evidenceCount(KernelBrowser $client, int $matchId): int
    {
        $match = $this->match($client, $matchId);
        return $this->em($client)->getRepository(CompetitionMatchEvidence::class)->count(['match' => $match]);
    }

    private function match(KernelBrowser $client, int $matchId): CompetitionMatch
    {
        $match = $this->em($client)->find(CompetitionMatch::class, $matchId);
        if (!$match instanceof CompetitionMatch) {
            throw new \LogicException('Competition match fixture was not found.');
        }

        return $match;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
