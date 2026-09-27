<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplicationQuestion;
use App\Entity\GuildRank;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildStructureOrderTest extends WebTestCase
{
    public function testManagerCanReorderRanksAndQuestionsUsingRenderedControls(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::GAMING]);
        [, $guild] = $this->guilds($client);

        $firstRank = (new GuildRank())
            ->setGuild($guild)
            ->setName('Officer')
            ->setPosition(10)
            ->setDefaultRank(true);
        $secondRank = (new GuildRank())
            ->setGuild($guild)
            ->setName('Member')
            ->setPosition(20);
        $firstQuestion = (new GuildApplicationQuestion())
            ->setGuild($guild)
            ->setLabel('Character')
            ->setPosition(10);
        $secondQuestion = (new GuildApplicationQuestion())
            ->setGuild($guild)
            ->setLabel('Experience')
            ->setPosition(20);

        $em = $this->em($client);
        foreach ([$firstRank, $secondRank, $firstQuestion, $secondQuestion] as $entry) {
            $em->persist($entry);
        }
        $em->flush();
        $client->loginUser($manager);

        $guildId = $guild->getId();
        $secondRankId = $secondRank->getId();
        $firstQuestionId = $firstQuestion->getId();
        self::assertNotNull($guildId);
        self::assertNotNull($secondRankId);
        self::assertNotNull($firstQuestionId);

        $crawler = $client->request('GET', '/admin/gaming/guild/'.$guildId.'/structure');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[disabled][aria-label="Rang Officer ist bereits an erster Stelle"]');
        self::assertSelectorExists('button[disabled][aria-label="Frage Character ist bereits an erster Stelle"]');
        self::assertSelectorExists(sprintf('form[data-order-type="rank"][data-order-id="%d"][data-direction="up"]', $secondRankId));
        self::assertSelectorExists(sprintf('form[data-order-type="question"][data-order-id="%d"][data-direction="down"]', $firstQuestionId));

        $rankForm = $crawler->filter(sprintf('form[data-order-type="rank"][data-order-id="%d"][data-direction="up"]', $secondRankId));
        $rankAction = $rankForm->attr('action');
        $rankToken = $rankForm->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($rankAction);
        self::assertNotNull($rankToken);

        $client->request('POST', $rankAction, ['_token' => $rankToken]);
        self::assertResponseRedirects('/admin/gaming/guild/'.$guildId.'/structure');

        $em->clear();
        $storedGuild = $em->find(Guild::class, $guildId);
        self::assertInstanceOf(Guild::class, $storedGuild);
        /** @var list<GuildRank> $ranks */
        $ranks = $em->getRepository(GuildRank::class)->findBy(['guild' => $storedGuild], ['position' => 'ASC', 'id' => 'ASC']);
        self::assertSame(['Member', 'Officer'], array_map(static fn (GuildRank $rank): string => $rank->getName(), $ranks));
        self::assertSame([0, 1], array_map(static fn (GuildRank $rank): int => $rank->getPosition(), $ranks));
        self::assertTrue($ranks[1]->isDefaultRank(), 'Reordering must preserve the selected default rank.');

        $questionForm = $crawler->filter(sprintf('form[data-order-type="question"][data-order-id="%d"][data-direction="down"]', $firstQuestionId));
        $questionAction = $questionForm->attr('action');
        $questionToken = $questionForm->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($questionAction);
        self::assertNotNull($questionToken);

        $client->request('POST', $questionAction, ['_token' => $questionToken]);
        self::assertResponseRedirects('/admin/gaming/guild/'.$guildId.'/structure');

        $em->clear();
        $storedGuild = $em->find(Guild::class, $guildId);
        self::assertInstanceOf(Guild::class, $storedGuild);
        /** @var list<GuildApplicationQuestion> $questions */
        $questions = $em->getRepository(GuildApplicationQuestion::class)->findBy(['guild' => $storedGuild], ['position' => 'ASC', 'id' => 'ASC']);
        self::assertSame(['Experience', 'Character'], array_map(static fn (GuildApplicationQuestion $question): string => $question->getLabel(), $questions));
        self::assertSame([0, 1], array_map(static fn (GuildApplicationQuestion $question): int => $question->getPosition(), $questions));
    }

    public function testInvalidCsrfAndCrossGuildQuestionCannotChangeOrder(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::GAMING]);
        [$firstGuild, $secondGuild] = $this->guilds($client);

        $firstRank = (new GuildRank())->setGuild($firstGuild)->setName('Alpha')->setPosition(10);
        $secondRank = (new GuildRank())->setGuild($firstGuild)->setName('Beta')->setPosition(20);
        $foreignFirstQuestion = (new GuildApplicationQuestion())->setGuild($secondGuild)->setLabel('Foreign first')->setPosition(10);
        $foreignSecondQuestion = (new GuildApplicationQuestion())->setGuild($secondGuild)->setLabel('Foreign second')->setPosition(20);

        $em = $this->em($client);
        foreach ([$firstRank, $secondRank, $foreignFirstQuestion, $foreignSecondQuestion] as $entry) {
            $em->persist($entry);
        }
        $em->flush();
        $client->loginUser($manager);

        $firstGuildId = $firstGuild->getId();
        $secondGuildId = $secondGuild->getId();
        $secondRankId = $secondRank->getId();
        $foreignSecondQuestionId = $foreignSecondQuestion->getId();
        self::assertNotNull($firstGuildId);
        self::assertNotNull($secondGuildId);
        self::assertNotNull($secondRankId);
        self::assertNotNull($foreignSecondQuestionId);

        $client->request(
            'POST',
            '/admin/gaming/guild/'.$firstGuildId.'/structure/rank/'.$secondRankId.'/move/up',
            ['_token' => 'invalid-token'],
        );
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/admin/gaming/guild/'.$secondGuildId.'/structure');
        self::assertResponseIsSuccessful();
        $foreignForm = $crawler->filter(sprintf('form[data-order-type="question"][data-order-id="%d"][data-direction="up"]', $foreignSecondQuestionId));
        $token = $foreignForm->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $client->request(
            'POST',
            '/admin/gaming/guild/'.$firstGuildId.'/structure/question/'.$foreignSecondQuestionId.'/move/up',
            ['_token' => $token],
        );
        self::assertResponseStatusCodeSame(404);

        $em->clear();
        $storedFirstGuild = $em->find(Guild::class, $firstGuildId);
        $storedSecondGuild = $em->find(Guild::class, $secondGuildId);
        self::assertInstanceOf(Guild::class, $storedFirstGuild);
        self::assertInstanceOf(Guild::class, $storedSecondGuild);
        /** @var list<GuildRank> $ranks */
        $ranks = $em->getRepository(GuildRank::class)->findBy(['guild' => $storedFirstGuild], ['position' => 'ASC', 'id' => 'ASC']);
        self::assertSame(['Alpha', 'Beta'], array_map(static fn (GuildRank $rank): string => $rank->getName(), $ranks));
        /** @var list<GuildApplicationQuestion> $questions */
        $questions = $em->getRepository(GuildApplicationQuestion::class)->findBy(['guild' => $storedSecondGuild], ['position' => 'ASC', 'id' => 'ASC']);
        self::assertSame(['Foreign first', 'Foreign second'], array_map(static fn (GuildApplicationQuestion $question): string => $question->getLabel(), $questions));
    }

    public function testRoutesRequireLoginAndGamingManagementPermission(): void
    {
        $client = static::createClient();
        [$guild] = $this->guilds($client);
        $rank = (new GuildRank())->setGuild($guild)->setName('Member')->setPosition(10);
        $this->em($client)->persist($rank);
        $this->em($client)->flush();

        $guildId = $guild->getId();
        $rankId = $rank->getId();
        self::assertNotNull($guildId);
        self::assertNotNull($rankId);
        $url = '/admin/gaming/guild/'.$guildId.'/structure/rank/'.$rankId.'/move/up';

        $client->request('POST', $url);
        self::assertResponseRedirects('/login');

        $unprivileged = $this->user($client, []);
        $client->loginUser($unprivileged);
        $client->request('POST', $url, ['_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(403);

        $this->em($client)->clear();
        $storedGuild = $this->em($client)->find(Guild::class, $guildId);
        self::assertInstanceOf(Guild::class, $storedGuild);
        /** @var list<GuildRank> $ranks */
        $ranks = $this->em($client)->getRepository(GuildRank::class)->findBy(['guild' => $storedGuild], ['position' => 'ASC', 'id' => 'ASC']);
        self::assertSame(['Member'], array_map(static fn (GuildRank $storedRank): string => $storedRank->getName(), $ranks));
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('guild-structure-order-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Guild structure order test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    /** @return array{Game, Guild, Guild} */
    private function guilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())
            ->setName('Order game '.$suffix)
            ->setSlug('order-game-'.$suffix);
        $firstGuild = (new Guild())
            ->setGame($game)
            ->setName('Order guild A '.$suffix)
            ->setSlug('order-guild-a-'.$suffix)
            ->setServerName('Server')
            ->setDescription('Structure ordering test guild A');
        $secondGuild = (new Guild())
            ->setGame($game)
            ->setName('Order guild B '.$suffix)
            ->setSlug('order-guild-b-'.$suffix)
            ->setServerName('Server')
            ->setDescription('Structure ordering test guild B');

        $em = $this->em($client);
        $em->persist($game);
        $em->persist($firstGuild);
        $em->persist($secondGuild);
        $em->flush();

        return [$firstGuild, $secondGuild];
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
