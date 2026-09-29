<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildApplicationDecisionSecurityTest extends WebTestCase
{
    public function testApplicationListingReviewAndDecisionRequireGamingPermission(): void
    {
        $client = static::createClient();
        $world = $this->createGuildWorld($client, 'denied');
        $user = $this->createUser($client, 'denied', [CmsPermission::CONTENT]);
        $application = $this->createApplication($client, $world['guild'], 'denied');
        $applicationId = $application->getId();
        self::assertNotNull($applicationId);
        $before = $this->applicationState($client, $applicationId);

        try {
            $client->loginUser($user);

            $client->request('GET', '/admin/gaming/applications');
            self::assertResponseStatusCodeSame(403);
            self::assertSame($before, $this->applicationState($client, $applicationId));

            $reviewPath = '/admin/gaming/applications/'.$applicationId.'/review';
            $client->request('GET', $reviewPath);
            self::assertResponseStatusCodeSame(403);
            self::assertSame($before, $this->applicationState($client, $applicationId));

            foreach (['accept', 'reject'] as $decision) {
                $client->request('POST', '/admin/gaming/applications/'.$applicationId.'/'.$decision);
                self::assertResponseStatusCodeSame(403);
                self::assertSame($before, $this->applicationState($client, $applicationId));
            }
        } finally {
            $this->cleanup($client, $user, $world['game'], $world['guild'], [$applicationId]);
        }
    }

    public function testReviewAndDecisionRoutesRequireTheirPurposeSpecificCsrfTokens(): void
    {
        $client = static::createClient();
        $world = $this->createGuildWorld($client, 'csrf');
        $reviewer = $this->createUser($client, 'reviewer', [CmsPermission::GAMING]);
        $reviewerId = $reviewer->getId();
        self::assertNotNull($reviewerId);
        $client->loginUser($reviewer);

        $acceptedApplication = $this->createApplication($client, $world['guild'], 'accepted', $reviewer);
        $rejectedApplication = $this->createApplication($client, $world['guild'], 'rejected', $reviewer);
        $acceptedId = $acceptedApplication->getId();
        $rejectedId = $rejectedApplication->getId();
        self::assertNotNull($acceptedId);
        self::assertNotNull($rejectedId);

        try {
            $acceptTokens = $this->renderedTokens($client, $acceptedId);
            $rejectTokens = $this->renderedTokens($client, $rejectedId);
            $beforeAccept = $this->applicationState($client, $acceptedId);
            $beforeReject = $this->applicationState($client, $rejectedId);

            foreach ([[], ['_token' => 'invalid'], ['_token' => $acceptTokens['accept']]] as $csrfParameters) {
                $client->request('POST', '/admin/gaming/applications/'.$acceptedId.'/review', [
                    'internal_notes' => 'Untrusted replacement note',
                    'release' => '1',
                    ...$csrfParameters,
                ]);

                self::assertResponseStatusCodeSame(403);
                self::assertSame($beforeAccept, $this->applicationState($client, $acceptedId));
            }

            foreach ([
                ['path' => $acceptTokens['accept_path'], 'wrong_purpose' => $acceptTokens['review']],
                ['path' => $rejectTokens['reject_path'], 'wrong_purpose' => $rejectTokens['review']],
            ] as $decision) {
                foreach ([[], ['_token' => 'invalid'], ['_token' => $decision['wrong_purpose']]] as $csrfParameters) {
                    $client->request('POST', $decision['path'], $csrfParameters);

                    self::assertResponseStatusCodeSame(403);
                    $applicationId = str_contains($decision['path'], '/'.$acceptedId.'/') ? $acceptedId : $rejectedId;
                    $baseline = $applicationId === $acceptedId ? $beforeAccept : $beforeReject;
                    self::assertSame($baseline, $this->applicationState($client, $applicationId));
                }
            }

            $client->request('POST', $acceptTokens['accept_path'], ['_token' => $acceptTokens['accept']]);
            self::assertResponseRedirects('/admin/gaming/applications');

            $acceptedState = $this->applicationState($client, $acceptedId);
            self::assertSame(GuildApplication::STATUS_ACCEPTED, $acceptedState['status']);
            self::assertNotNull($acceptedState['converted_member']);
            self::assertSame($beforeAccept['guild_members'] + 1, $acceptedState['guild_members']);
            self::assertSame($beforeAccept['accept_audit'] + 1, $acceptedState['accept_audit']);

            $client->request('POST', $rejectTokens['reject_path'], ['_token' => $rejectTokens['reject']]);
            self::assertResponseRedirects('/admin/gaming/applications');

            $rejectedState = $this->applicationState($client, $rejectedId);
            self::assertSame(GuildApplication::STATUS_REJECTED, $rejectedState['status']);
            self::assertNull($rejectedState['converted_member']);
            self::assertSame($beforeReject['guild_members'] + 1, $rejectedState['guild_members']);
            self::assertSame($beforeReject['reject_audit'] + 1, $rejectedState['reject_audit']);
        } finally {
            $this->cleanup($client, $reviewer, $world['game'], $world['guild'], [$acceptedId, $rejectedId]);
        }
    }

    /**
     * @return array{game: Game, guild: Guild}
     */
    private function createGuildWorld(KernelBrowser $client, string $label): array
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())
            ->setName('Application security '.$label.' '.$suffix)
            ->setSlug('application-security-'.$label.'-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Application security guild '.$suffix)
            ->setSlug('application-security-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Guild application decision security fixture');

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->flush();

        return ['game' => $game, 'guild' => $guild];
    }

    private function createApplication(
        KernelBrowser $client,
        Guild $guild,
        string $label,
        ?User $reviewer = null,
    ): GuildApplication {
        $suffix = bin2hex(random_bytes(6));
        $application = (new GuildApplication())
            ->setGuild($guild)
            ->setApplicantName('Applicant '.$label)
            ->setEmail('application-decision-'.$label.'-'.$suffix.'@example.test')
            ->setCharacterName('Character '.$label)
            ->setMessage('This synthetic guild application is long enough to pass validation.');

        if ($reviewer !== null) {
            $application->assignTo($reviewer)->setInternalNotes('Existing review note');
        }

        $entityManager = $this->entityManager($client);
        $entityManager->persist($application);
        $entityManager->flush();

        return $application;
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('application-decision-security-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Application decision security '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /**
     * @return array{review: string, accept: string, reject: string, accept_path: string, reject_path: string}
     */
    private function renderedTokens(KernelBrowser $client, int $applicationId): array
    {
        $reviewPath = '/admin/gaming/applications/'.$applicationId.'/review';
        $acceptPath = '/admin/gaming/applications/'.$applicationId.'/accept';
        $rejectPath = '/admin/gaming/applications/'.$applicationId.'/reject';
        $crawler = $client->request('GET', $reviewPath);
        self::assertResponseIsSuccessful();

        $review = (string) $crawler->filter('form.content-form input[name="_token"]')->attr('value');
        $accept = (string) $crawler->filter('form[action="'.$acceptPath.'"] input[name="_token"]')->attr('value');
        $reject = (string) $crawler->filter('form[action="'.$rejectPath.'"] input[name="_token"]')->attr('value');
        self::assertNotSame('', $review);
        self::assertNotSame('', $accept);
        self::assertNotSame('', $reject);

        return [
            'review' => $review,
            'accept' => $accept,
            'reject' => $reject,
            'accept_path' => $acceptPath,
            'reject_path' => $rejectPath,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     assigned_to: int|null,
     *     internal_notes: string|null,
     *     converted_member: int|null,
     *     guild_members: int,
     *     review_audit: int,
     *     accept_audit: int,
     *     reject_audit: int
     * }
     */
    private function applicationState(KernelBrowser $client, int $applicationId): array
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $application = $entityManager->find(GuildApplication::class, $applicationId);
        self::assertInstanceOf(GuildApplication::class, $application);
        $guild = $application->getGuild();
        self::assertNotNull($guild);
        $convertedMember = $application->getConvertedMember();

        return [
            'status' => $application->getStatus(),
            'assigned_to' => $application->getAssignedTo()?->getId(),
            'internal_notes' => $application->getInternalNotes(),
            'converted_member' => $convertedMember?->getId(),
            'guild_members' => $entityManager->getRepository(GuildMember::class)->count(['guild' => $guild]),
            'review_audit' => $entityManager->getRepository(AuditLog::class)->count(['action' => 'guild_application.review']),
            'accept_audit' => $entityManager->getRepository(AuditLog::class)->count(['action' => 'guild_application.accept']),
            'reject_audit' => $entityManager->getRepository(AuditLog::class)->count(['action' => 'guild_application.reject']),
        ];
    }

    /**
     * @param list<int> $applicationIds
     */
    private function cleanup(
        KernelBrowser $client,
        User $user,
        Game $game,
        Guild $guild,
        array $applicationIds,
    ): void {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($applicationIds as $applicationId) {
            $application = $entityManager->find(GuildApplication::class, $applicationId);
            if ($application instanceof GuildApplication) {
                $application->setConvertedMember(null);
                $entityManager->remove($application);
            }
        }
        $entityManager->flush();

        $guildId = $guild->getId();
        if ($guildId !== null) {
            $storedGuild = $entityManager->find(Guild::class, $guildId);
            if ($storedGuild instanceof Guild) {
                foreach ($entityManager->getRepository(GuildMember::class)->findBy(['guild' => $storedGuild]) as $member) {
                    if ($member instanceof GuildMember) {
                        $entityManager->remove($member);
                    }
                }
                $entityManager->flush();
                $entityManager->remove($storedGuild);
                $entityManager->flush();
            }
        }

        $gameId = $game->getId();
        if ($gameId !== null) {
            $storedGame = $entityManager->find(Game::class, $gameId);
            if ($storedGame instanceof Game) {
                $entityManager->remove($storedGame);
                $entityManager->flush();
            }
        }

        $userId = $user->getId();
        if ($userId !== null) {
            $storedUser = $entityManager->find(User::class, $userId);
            if ($storedUser instanceof User) {
                foreach ($entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]) as $entry) {
                    if ($entry instanceof AuditLog) {
                        $entityManager->remove($entry);
                    }
                }
                $entityManager->remove($storedUser);
                $entityManager->flush();
            }
        }

        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
