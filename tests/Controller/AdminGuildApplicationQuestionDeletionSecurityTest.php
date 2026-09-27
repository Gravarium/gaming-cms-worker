<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplicationQuestion;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildApplicationQuestionDeletionSecurityTest extends WebTestCase
{
    public function testQuestionStructureAndDeletionRequireGamingManagementPermission(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $fixtures = $this->createGuilds($client, $suffix);
        $question = $this->createQuestion($client, $fixtures['guild'], 'Protected question '.$suffix);
        $user = $this->createUser($client, 'denied-'.$suffix, [CmsPermission::CONTENT]);
        $questionId = $this->requireId($question->getId());
        $userId = $this->requireId($user->getId());
        $guildId = $this->requireId($fixtures['guild']->getId());
        $ids = $this->fixtureIds($fixtures, $userId, [$questionId]);
        $client->loginUser($user);

        try {
            $structurePath = '/admin/gaming/guild/'.$guildId.'/structure';
            $deletePath = $structurePath.'/question/'.$questionId.'/delete';

            $client->request('GET', $structurePath);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', $deletePath);
            self::assertResponseStatusCodeSame(403);
            $this->assertQuestion($client, $questionId, $guildId, 'Protected question '.$suffix);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testQuestionDeletionRequiresItsRenderedTokenAndOwningGuild(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $fixtures = $this->createGuilds($client, $suffix);
        $target = $this->createQuestion($client, $fixtures['guild'], 'Target question '.$suffix);
        $sibling = $this->createQuestion($client, $fixtures['guild'], 'Sibling question '.$suffix);
        $foreign = $this->createQuestion($client, $fixtures['otherGuild'], 'Foreign question '.$suffix);
        $manager = $this->createUser($client, 'manager-'.$suffix, [CmsPermission::GAMING]);
        $targetId = $this->requireId($target->getId());
        $siblingId = $this->requireId($sibling->getId());
        $foreignId = $this->requireId($foreign->getId());
        $managerId = $this->requireId($manager->getId());
        $guildId = $this->requireId($fixtures['guild']->getId());
        $otherGuildId = $this->requireId($fixtures['otherGuild']->getId());
        $ids = $this->fixtureIds($fixtures, $managerId, [$targetId, $siblingId, $foreignId]);
        $client->loginUser($manager);

        try {
            $structurePath = '/admin/gaming/guild/'.$guildId.'/structure';
            $targetPath = $structurePath.'/question/'.$targetId.'/delete';
            $siblingPath = $structurePath.'/question/'.$siblingId.'/delete';
            $targetToken = $this->renderedDeleteToken($client, $structurePath, $targetPath);
            $siblingToken = $this->renderedDeleteToken($client, $structurePath, $siblingPath);

            foreach ([[], ['_token' => 'invalid'], ['_token' => $siblingToken]] as $parameters) {
                $client->request('POST', $targetPath, $parameters);

                self::assertResponseStatusCodeSame(403);
                $this->assertQuestion($client, $targetId, $guildId, 'Target question '.$suffix);
                $this->assertQuestion($client, $siblingId, $guildId, 'Sibling question '.$suffix);
                $this->assertQuestion($client, $foreignId, $otherGuildId, 'Foreign question '.$suffix);
            }

            $wrongGuildPath = '/admin/gaming/guild/'.$otherGuildId.'/structure/question/'.$targetId.'/delete';
            $client->request('POST', $wrongGuildPath, ['_token' => $targetToken]);

            self::assertResponseStatusCodeSame(404);
            $this->assertQuestion($client, $targetId, $guildId, 'Target question '.$suffix);
            $this->assertQuestion($client, $siblingId, $guildId, 'Sibling question '.$suffix);
            $this->assertQuestion($client, $foreignId, $otherGuildId, 'Foreign question '.$suffix);

            $client->request('POST', $targetPath, ['_token' => $targetToken]);

            self::assertResponseRedirects($structurePath);
            self::assertNull($this->findQuestion($client, $targetId));
            $this->assertQuestion($client, $siblingId, $guildId, 'Sibling question '.$suffix);
            $this->assertQuestion($client, $foreignId, $otherGuildId, 'Foreign question '.$suffix);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    private function renderedDeleteToken(KernelBrowser $client, string $structurePath, string $deletePath): string
    {
        $crawler = $client->request('GET', $structurePath);
        self::assertResponseIsSuccessful();

        $token = (string) $crawler
            ->filter('form[action="'.$deletePath.'"] input[name="_token"]')
            ->attr('value');
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @return array{game: Game, guild: Guild, otherGuild: Guild}
     */
    private function createGuilds(KernelBrowser $client, string $suffix): array
    {
        $game = (new Game())
            ->setName('Guild question security game '.$suffix)
            ->setSlug('guild-question-security-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Question owner guild '.$suffix)
            ->setSlug('question-owner-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic question deletion fixture');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Question other guild '.$suffix)
            ->setSlug('question-other-guild-'.$suffix)
            ->setServerName('Other test server')
            ->setDescription('Other synthetic question deletion fixture');

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($otherGuild);
        $entityManager->flush();

        return ['game' => $game, 'guild' => $guild, 'otherGuild' => $otherGuild];
    }

    private function createQuestion(KernelBrowser $client, Guild $guild, string $label): GuildApplicationQuestion
    {
        $question = (new GuildApplicationQuestion())
            ->setGuild($guild)
            ->setLabel($label)
            ->setType(GuildApplicationQuestion::TYPE_TEXT)
            ->setPosition(0);
        $this->entityManager($client)->persist($question);
        $this->entityManager($client)->flush();

        return $question;
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('guild-question-deletion-'.$label.'@example.test')
            ->setDisplayName('Guild question deletion '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /**
     * @param array{game: Game, guild: Guild, otherGuild: Guild} $fixtures
     * @param list<int> $questionIds
     * @return array{gameId: int, guildIds: list<int>, questionIds: list<int>, userId: int}
     */
    private function fixtureIds(array $fixtures, int $userId, array $questionIds): array
    {
        return [
            'gameId' => $this->requireId($fixtures['game']->getId()),
            'guildIds' => [
                $this->requireId($fixtures['guild']->getId()),
                $this->requireId($fixtures['otherGuild']->getId()),
            ],
            'questionIds' => $questionIds,
            'userId' => $userId,
        ];
    }

    private function findQuestion(KernelBrowser $client, int $questionId): ?GuildApplicationQuestion
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $question = $entityManager->find(GuildApplicationQuestion::class, $questionId);

        return $question instanceof GuildApplicationQuestion ? $question : null;
    }

    private function assertQuestion(KernelBrowser $client, int $questionId, int $guildId, string $label): void
    {
        $question = $this->findQuestion($client, $questionId);
        self::assertInstanceOf(GuildApplicationQuestion::class, $question);
        self::assertSame($guildId, $question->getGuild()?->getId());
        self::assertSame($label, $question->getLabel());
    }

    /**
     * @param array{gameId: int, guildIds: list<int>, questionIds: list<int>, userId: int} $ids
     */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($ids['questionIds'] as $questionId) {
            $question = $entityManager->find(GuildApplicationQuestion::class, $questionId);
            if ($question instanceof GuildApplicationQuestion) {
                $entityManager->remove($question);
            }
        }

        $user = $entityManager->find(User::class, $ids['userId']);
        if ($user instanceof User) {
            $entityManager->remove($user);
        }

        foreach ($ids['guildIds'] as $guildId) {
            $guild = $entityManager->find(Guild::class, $guildId);
            if ($guild instanceof Guild) {
                $entityManager->remove($guild);
            }
        }

        $game = $entityManager->find(Game::class, $ids['gameId']);
        if ($game instanceof Game) {
            $entityManager->remove($game);
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new LogicException('Persisted fixture has no identifier.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
