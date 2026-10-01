<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionMatchEvidence;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCompetitionEvidenceTest extends WebTestCase
{
    public function testManagerSeesOnlyCompetitionEvidenceWithoutEmailAndUnsafeLegacyLinks(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $em = $this->em($client);
        $safeUrl = 'https://evidence.example.test/proof-'.bin2hex(random_bytes(4));
        $safe = $this->evidence($em, $fixture['match'], $fixture['submitter'], CompetitionMatchEvidence::TYPE_SCREENSHOT, $safeUrl);
        $unsafe = $this->evidence($em, $fixture['match'], $fixture['submitter'], CompetitionMatchEvidence::TYPE_URL, 'https://evidence.example.test/legacy');

        $foreign = $this->competitionFixture($em, 'Foreign');
        $foreignUrl = 'https://foreign.example.test/private-'.bin2hex(random_bytes(4));
        $this->evidence($em, $foreign['match'], $foreign['submitter'], CompetitionMatchEvidence::TYPE_VIDEO, $foreignUrl);
        $em->flush();

        self::assertNotNull($unsafe->getId());
        $em->getConnection()->executeStatement(
            'UPDATE competition_match_evidence SET locator = :locator WHERE id = :id',
            ['locator' => 'file:///private/legacy-proof', 'id' => $unsafe->getId()],
        );
        $em->clear();

        $client->loginUser($fixture['manager']);
        $client->request('GET', $this->path($fixture['competitionId']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $fixture['competitionName']);
        self::assertSelectorTextContains('main', '2 Belege');
        self::assertSelectorExists('a[href="'.$safeUrl.'"][rel="noopener noreferrer"]');
        self::assertSelectorTextContains('main', 'Ungültiger Link entfernt');
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($fixture['submitter']->getDisplayName(), $body);
        self::assertStringContainsString($fixture['participantAName'], $body);
        self::assertStringContainsString($fixture['participantBName'], $body);
        self::assertStringNotContainsString($fixture['submitter']->getEmail(), $body);
        self::assertStringNotContainsString($fixture['manager']->getEmail(), $body);
        self::assertStringNotContainsString($foreignUrl, $body);
        self::assertStringNotContainsString('file:///private/legacy-proof', $body);
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function testTypeAndPageFiltersAreStrictAndRouteIsReadOnly(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $em = $this->em($client);
        $this->evidence($em, $fixture['match'], $fixture['submitter'], CompetitionMatchEvidence::TYPE_SCREENSHOT, 'https://example.test/screenshot');
        $this->evidence($em, $fixture['match'], $fixture['submitter'], CompetitionMatchEvidence::TYPE_VIDEO, 'https://example.test/video');
        $em->flush();
        $client->loginUser($fixture['manager']);

        $client->request('GET', $this->path($fixture['competitionId']).'?type=video');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '1 Beleg');
        self::assertSelectorExists('a[href="https://example.test/video"]');
        self::assertSelectorNotExists('a[href="https://example.test/screenshot"]');

        foreach (['?type=archive', '?page=0', '?page=5', '?page[]=1', '?unexpected=1'] as $query) {
            $client->request('GET', $this->path($fixture['competitionId']).$query);
            self::assertResponseStatusCodeSame(400);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        }

        $before = $this->em($client)->getRepository(CompetitionMatchEvidence::class)->count([]);
        $client->request('POST', $this->path($fixture['competitionId']));
        self::assertResponseStatusCodeSame(405);
        self::assertSame($before, $this->em($client)->getRepository(CompetitionMatchEvidence::class)->count([]));
    }

    public function testBoardExposesOnlyTheNewestHundredRecords(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $em = $this->em($client);
        $oldest = 'https://example.test/oldest-'.bin2hex(random_bytes(4));
        $this->evidence($em, $fixture['match'], $fixture['submitter'], CompetitionMatchEvidence::TYPE_URL, $oldest);
        $em->flush();
        for ($index = 1; $index <= 100; ++$index) {
            $this->evidence($em, $fixture['match'], $fixture['submitter'], CompetitionMatchEvidence::TYPE_URL, 'https://example.test/new-'.$index.'-'.bin2hex(random_bytes(2)));
        }
        $em->flush();
        $client->loginUser($fixture['manager']);

        $client->request('GET', $this->path($fixture['competitionId']).'?page=4');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '101 Belege; die neuesten 100 sind abrufbar');
        self::assertSelectorCount(25, 'tbody tr');
        self::assertStringNotContainsString($oldest, (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('nav', 'Seite 4 von 4');
    }

    public function testPermissionAndGamingModuleAreEnforced(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $fixture = $this->fixture($client);
        $unprivileged = $this->user($this->em($client), 'Unprivileged', []);
        $this->em($client)->flush();
        $client->loginUser($unprivileged);
        $client->request('GET', $this->path($fixture['competitionId']));
        self::assertResponseStatusCodeSame(403);

        $modules = $client->getContainer()->get(CmsModuleManager::class);
        $wasEnabled = $modules->isEnabled('gaming');
        $modules->setEnabled('gaming', false);
        $client->loginUser($fixture['manager']);
        try {
            $client->request('GET', $this->path($fixture['competitionId']));
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($wasEnabled) {
                $modules->setEnabled('gaming', true);
            }
        }
    }

    /**
     * @return array{
     *     competitionId: int,
     *     competitionName: string,
     *     match: CompetitionMatch,
     *     submitter: User,
     *     manager: User,
     *     participantAName: string,
     *     participantBName: string
     * }
     */
    private function fixture(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $competition = $this->competitionFixture($em, 'Review');
        $manager = $this->user($em, 'Evidence manager', ['CMS_GAMING_MANAGE']);
        $em->flush();
        $competitionId = $competition['competition']->getId();
        if ($competitionId === null) {
            throw new \LogicException('Competition fixture has no identifier.');
        }

        return [
            'competitionId' => $competitionId,
            'competitionName' => $competition['competition']->getName(),
            'match' => $competition['match'],
            'submitter' => $competition['submitter'],
            'manager' => $manager,
            'participantAName' => $competition['participantA']->getName(),
            'participantBName' => $competition['participantB']->getName(),
        ];
    }

    /** @return array{competition: Competition, match: CompetitionMatch, submitter: User, participantA: CompetitionParticipant, participantB: CompetitionParticipant} */
    private function competitionFixture(EntityManagerInterface $em, string $label): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName($label.' game')->setSlug(strtolower($label).'-game-'.$suffix);
        $competition = (new Competition())
            ->setGame($game)
            ->setName($label.' evidence cup '.$suffix)
            ->setSlug(strtolower($label).'-evidence-'.$suffix)
            ->open()
            ->start();
        $submitter = $this->user($em, $label.' submitter', []);
        $opponent = $this->user($em, $label.' opponent', []);
        $participantA = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($submitter)->setName($label.' Alpha')->checkIn();
        $participantB = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($opponent)->setName($label.' Bravo')->checkIn();
        $match = (new CompetitionMatch())
            ->setCompetition($competition)
            ->setRoundNumber(2)
            ->setSequence(3)
            ->setParticipants($participantA, $participantB)
            ->markReady();
        foreach ([$game, $competition, $participantA, $participantB, $match] as $entity) {
            $em->persist($entity);
        }

        return [
            'competition' => $competition,
            'match' => $match,
            'submitter' => $submitter,
            'participantA' => $participantA,
            'participantB' => $participantB,
        ];
    }

    private function evidence(EntityManagerInterface $em, CompetitionMatch $match, User $submitter, string $type, string $url): CompetitionMatchEvidence
    {
        $evidence = (new CompetitionMatchEvidence())
            ->setMatch($match)
            ->setSubmittedBy($submitter)
            ->setType($type)
            ->setLocator($url);
        $em->persist($evidence);

        return $evidence;
    }

    /** @param list<string> $permissions */
    private function user(EntityManagerInterface $em, string $name, array $permissions): User
    {
        $user = (new User())
            ->setEmail(strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName($name)
            ->setPermissions($permissions)
            ->verifyEmail();
        $em->persist($user);

        return $user;
    }

    private function path(int $competitionId): string
    {
        return '/admin/gaming/competitions/'.$competitionId.'/evidence';
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
