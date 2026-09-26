<?php

declare(strict_types=1);

namespace App\Tests\Controller\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompetitionRegistrationSecurityTest extends WebTestCase
{
    public function testPublicOpenCompetitionRegistrationPersistsOnceAndRejectsDuplicates(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['user']);

        $path = '/competitions/'.$fixture['competitionId'].'/register';
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', $fixture['publicName']);

        $form = $crawler->selectButton('Anmelden')->form([
            'competition_registration[name]' => 'Solo participant '.$fixture['suffix'],
            'competition_registration[kind]' => CompetitionParticipant::KIND_SOLO,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/competitions/'.$fixture['competitionId']);
        self::assertSame(1, $this->participantCount($client, $fixture['competitionId'], $fixture['userId']));

        $client->request('GET', $path);
        self::assertResponseRedirects('/competitions/'.$fixture['competitionId']);
        self::assertSame(1, $this->participantCount($client, $fixture['competitionId'], $fixture['userId']));
    }

    public function testPrivateDraftInProgressAndDisabledGameCompetitionsCannotBeRegisteredFor(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['user']);

        foreach ($fixture['hiddenIds'] as $id) {
            $client->request('GET', '/competitions/'.$id.'/register');
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testInvalidRegistrationCsrfTokenDoesNotPersistParticipant(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['user']);

        $crawler = $client->request('GET', '/competitions/'.$fixture['competitionId'].'/register');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Anmelden')->form([
            'competition_registration[name]' => 'Forged participant',
            'competition_registration[kind]' => CompetitionParticipant::KIND_SOLO,
            'competition_registration[_token]' => 'invalid-token',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->participantCount($client, $fixture['competitionId'], $fixture['userId']));
    }

    /**
     * @return array{
     *   competitionId: int,
     *   publicName: string,
     *   userId: int,
     *   user: User,
     *   suffix: string,
     *   hiddenIds: list<int>
     * }
     */
    private function fixture(KernelBrowser $client): array
    {
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $enabledGame = (new Game())
            ->setName('Registration Game '.$suffix)
            ->setSlug('registration-game-'.$suffix)
            ->setEnabled(true);
        $disabledGame = (new Game())
            ->setName('Disabled Registration Game '.$suffix)
            ->setSlug('disabled-registration-game-'.$suffix)
            ->setEnabled(true);
        $user = (new User())
            ->setEmail('competition-registration-'.$suffix.'@example.test')
            ->setDisplayName('Competition registration user')
            ->setPermissions([])
            ->verifyEmail();

        $publicName = 'Public Registration Cup '.$suffix;
        $public = (new Competition())
            ->setGame($enabledGame)
            ->setName($publicName)
            ->setSlug('public-registration-'.$suffix)
            ->open();
        $private = (new Competition())
            ->setGame($enabledGame)
            ->setName('Private Registration Cup '.$suffix)
            ->setSlug('private-registration-'.$suffix)
            ->setVisibility(Competition::VISIBILITY_PRIVATE)
            ->open();
        $draft = (new Competition())
            ->setGame($enabledGame)
            ->setName('Draft Registration Cup '.$suffix)
            ->setSlug('draft-registration-'.$suffix);
        $inProgress = (new Competition())
            ->setGame($enabledGame)
            ->setName('Started Registration Cup '.$suffix)
            ->setSlug('started-registration-'.$suffix)
            ->open()
            ->start();
        $disabled = (new Competition())
            ->setGame($disabledGame)
            ->setName('Disabled Game Registration Cup '.$suffix)
            ->setSlug('disabled-game-registration-'.$suffix)
            ->open();
        $disabledGame->setEnabled(false);

        foreach ([$enabledGame, $disabledGame, $user, $public, $private, $draft, $inProgress, $disabled] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $competitionId = $public->getId();
        $userId = $user->getId();
        $hiddenIds = [$private->getId(), $draft->getId(), $inProgress->getId(), $disabled->getId()];
        if ($competitionId === null || $userId === null || in_array(null, $hiddenIds, true)) {
            throw new \LogicException('Competition registration fixture did not receive database identifiers.');
        }

        return [
            'competitionId' => $competitionId,
            'publicName' => $publicName,
            'userId' => $userId,
            'user' => $user,
            'suffix' => $suffix,
            'hiddenIds' => $hiddenIds,
        ];
    }

    private function participantCount(KernelBrowser $client, int $competitionId, int $userId): int
    {
        $entityManager = $this->em($client);
        $competition = $entityManager->find(Competition::class, $competitionId);
        $user = $entityManager->find(User::class, $userId);
        if (!$competition instanceof Competition || !$user instanceof User) {
            throw new \LogicException('Competition registration fixture was not found.');
        }

        return $entityManager->getRepository(CompetitionParticipant::class)->count([
            'competition' => $competition,
            'captain' => $user,
        ]);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
