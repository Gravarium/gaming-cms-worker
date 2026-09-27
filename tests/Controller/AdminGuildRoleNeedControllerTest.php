<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\Guild\GuildRoleNeed;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildRoleNeedControllerTest extends WebTestCase
{
    public function testAdminCanCreateEditAndDeleteRoleNeedWithAuditRecords(): void
    {
        $client = static::createClient();
        [$guild] = $this->guilds($client);
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $url = '/admin/gaming/guild/'.$guild->getId().'/role-needs';

        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Bedarf anlegen')->form([
            'guild_role_need[roleKey]' => 'Heiler',
            'guild_role_need[classKey]' => 'Priester',
            'guild_role_need[desiredCount]' => '2',
        ]);
        $client->submit($form);

        self::assertResponseRedirects($url);
        $need = $this->em($client)->getRepository(GuildRoleNeed::class)->findOneBy(['guild' => $guild, 'roleKey' => 'Heiler', 'classKey' => 'Priester']);
        self::assertInstanceOf(GuildRoleNeed::class, $need);
        self::assertSame(2, $need->getDesiredCount());
        self::assertTrue($need->isActive());
        self::assertSame($guild->getGame()?->getId(), $need->getGame()->getId(), 'A role need always uses its owning guild game.');
        self::assertSame(1, $this->em($client)->getRepository(AuditLog::class)->count(['action' => 'guild_role_need.created']));

        $row = $this->em($client)->getRepository(GuildRoleNeed::class)->createQueryBuilder('need')
            ->select('need.id')->andWhere('need.guild = :guild')->setParameter('guild', $guild)->getQuery()->getSingleScalarResult();
        $editUrl = $url.'/'.$row.'/edit';
        $crawler = $client->request('GET', $editUrl);
        $form = $crawler->selectButton('Änderungen speichern')->form([
            'guild_role_need[desiredCount]' => '4',
        ]);
        $form['guild_role_need[active]']->untick();
        $client->submit($form);

        self::assertResponseRedirects($url);
        $this->em($client)->clear();
        $need = $this->em($client)->getRepository(GuildRoleNeed::class)->findOneBy(['guild' => $guild, 'roleKey' => 'Heiler', 'classKey' => 'Priester']);
        self::assertInstanceOf(GuildRoleNeed::class, $need);
        self::assertSame(4, $need->getDesiredCount());
        self::assertFalse($need->isActive());
        self::assertSame(1, $this->em($client)->getRepository(AuditLog::class)->count(['action' => 'guild_role_need.updated']));

        $crawler = $client->request('GET', $url);
        $deleteForm = $crawler->filter('form[action="'.$url.'/'.$row.'/delete"]')->form();
        $client->submit($deleteForm);

        self::assertResponseRedirects($url);
        self::assertNull($this->em($client)->getRepository(GuildRoleNeed::class)->findOneBy(['guild' => $guild, 'roleKey' => 'Heiler', 'classKey' => 'Priester']));
        self::assertSame(1, $this->em($client)->getRepository(AuditLog::class)->count(['action' => 'guild_role_need.deleted']));
    }

    public function testExactKeyLimitsAreAcceptedAndDuplicateOrInvalidInputsAreRejected(): void
    {
        $client = static::createClient();
        [$guild] = $this->guilds($client);
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $url = '/admin/gaming/guild/'.$guild->getId().'/role-needs';

        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Bedarf anlegen')->form([
            'guild_role_need[roleKey]' => str_repeat('R', 40),
            'guild_role_need[classKey]' => str_repeat('C', 100),
            'guild_role_need[desiredCount]' => '1000',
        ]);
        $client->submit($form);

        self::assertResponseRedirects($url);
        self::assertSame(1, $this->em($client)->getRepository(GuildRoleNeed::class)->count(['guild' => $guild]));

        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Bedarf anlegen')->form([
            'guild_role_need[roleKey]' => str_repeat('R', 40),
            'guild_role_need[classKey]' => str_repeat('C', 100),
            'guild_role_need[desiredCount]' => '1',
        ]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->em($client)->getRepository(GuildRoleNeed::class)->count(['guild' => $guild]));

        foreach ([
            ['roleKey' => str_repeat('R', 41), 'classKey' => 'Krieger', 'desiredCount' => '1'],
            ['roleKey' => 'Tank', 'classKey' => str_repeat('C', 101), 'desiredCount' => '1'],
            ['roleKey' => '', 'classKey' => 'Krieger', 'desiredCount' => '1'],
            ['roleKey' => 'Tank', 'classKey' => 'Krieger', 'desiredCount' => '-1'],
            ['roleKey' => 'Tank', 'classKey' => 'Krieger', 'desiredCount' => '1001'],
        ] as $values) {
            $crawler = $client->request('GET', $url);
            $form = $crawler->selectButton('Bedarf anlegen')->form([
                'guild_role_need[roleKey]' => $values['roleKey'],
                'guild_role_need[classKey]' => $values['classKey'],
                'guild_role_need[desiredCount]' => $values['desiredCount'],
            ]);
            $client->submit($form);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(1, $this->em($client)->getRepository(GuildRoleNeed::class)->count(['guild' => $guild]));
        }
    }

    public function testMissingPermissionModuleGateAndCsrfKeepStateProtected(): void
    {
        $client = static::createClient();
        [$guild] = $this->guilds($client);
        $url = '/admin/gaming/guild/'.$guild->getId().'/role-needs';

        $client->loginUser($this->user($client, [CmsPermission::CONTENT]));
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $this->setGamingEnabled($client, false);
        try {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->setGamingEnabled($client, true);
        }

        $crawler = $client->request('GET', $url);
        $client->request('POST', $url.'/new', [
            'guild_role_need' => ['roleKey' => 'Tank', 'classKey' => 'Krieger', 'desiredCount' => '1'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em($client)->getRepository(GuildRoleNeed::class)->count(['guild' => $guild]));

        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Bedarf anlegen')->form([
            'guild_role_need[roleKey]' => 'Tank',
            'guild_role_need[classKey]' => 'Krieger',
            'guild_role_need[desiredCount]' => '1',
        ]);
        $form['guild_role_need[_token]'] = 'forged';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em($client)->getRepository(GuildRoleNeed::class)->count(['guild' => $guild]));

        $managedGuild = $this->em($client)->find(Guild::class, $guild->getId());
        self::assertInstanceOf(Guild::class, $managedGuild);
        $game = $managedGuild->getGame();
        self::assertInstanceOf(Game::class, $game);
        $need = new GuildRoleNeed($managedGuild, $game, 'Barde', 'Support');
        $this->em($client)->persist($need);
        $this->em($client)->flush();
        $id = $this->em($client)->getClassMetadata(GuildRoleNeed::class)->getIdentifierValues($need)['id'];

        $client->request('POST', $url.'/'.$id.'/delete');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url.'/'.$id.'/delete', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em($client)->getRepository(GuildRoleNeed::class)->find($id));
    }

    public function testForeignGuildNeedCannotBeEditedAndReviewLinksToOwningGuildManager(): void
    {
        $client = static::createClient();
        [$guild, $otherGuild] = $this->guilds($client);
        $game = $otherGuild->getGame();
        self::assertInstanceOf(Game::class, $game);
        $need = new GuildRoleNeed($otherGuild, $game, 'Heiler', 'Priester');
        $application = (new GuildApplication())->setGuild($guild);
        $this->em($client)->persist($need);
        $this->em($client)->persist($application);
        $this->em($client)->flush();
        $id = $this->em($client)->getClassMetadata(GuildRoleNeed::class)->getIdentifierValues($need)['id'];

        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $client->request('GET', '/admin/gaming/guild/'.$guild->getId().'/role-needs/'.$id.'/edit');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/gaming/applications/'.$application->getId().'/review');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/gaming/guild/'.$guild->getId().'/role-needs"]');
    }

    private function setGamingEnabled(KernelBrowser $client, bool $enabled): void
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();
    }

    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('role-needs-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Role needs tester')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    /** @return array{Guild, Guild} */
    private function guilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(4));
        $game = (new Game())->setName('Game '.$suffix)->setSlug('game-'.$suffix);
        $guild = (new Guild())->setGame($game)->setName('Guild A '.$suffix)->setSlug('guild-a-'.$suffix)->setServerName('Server')->setDescription('Guild A');
        $other = (new Guild())->setGame($game)->setName('Guild B '.$suffix)->setSlug('guild-b-'.$suffix)->setServerName('Server')->setDescription('Guild B');
        $this->em($client)->persist($game);
        $this->em($client)->persist($guild);
        $this->em($client)->persist($other);
        $this->em($client)->flush();

        return [$guild, $other];
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
