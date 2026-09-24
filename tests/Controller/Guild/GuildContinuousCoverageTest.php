<?php

declare(strict_types=1);

namespace App\Tests\Controller\Guild;

use App\Entity\ExternalConnectorTarget;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\GuildEvent;
use App\Entity\GuildMember;
use App\Entity\GuildRank;
use App\Entity\MemberNotification;
use App\Entity\User;
use App\ExternalConnector\DiscordGuildNotificationConnectorAdapter;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class GuildContinuousCoverageTest extends WebTestCase
{
    public function testCrossGuildCollaborationAndStructureResourcesAreHidden(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::GAMING]);
        [$guild, $otherGuild] = $this->guilds($client);

        $foreignEvent = (new GuildEvent())
            ->setGuild($otherGuild)
            ->setTitle('Foreign event')
            ->setDescription('Must not be reachable through another guild route');
        $foreignRank = (new GuildRank())
            ->setGuild($otherGuild)
            ->setName('Foreign rank');

        $this->em($client)->persist($foreignEvent);
        $this->em($client)->persist($foreignRank);
        $this->em($client)->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/gaming/guild/'.$guild->getId().'/collaboration/event/'.$foreignEvent->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/admin/gaming/guild/'.$guild->getId().'/collaboration/event/'.$foreignEvent->getId().'/delete');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/gaming/guild/'.$guild->getId().'/structure/rank/'.$foreignRank->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/admin/gaming/guild/'.$guild->getId().'/structure/rank/'.$foreignRank->getId().'/delete');
        self::assertResponseStatusCodeSame(404);

        self::assertNotNull($this->em($client)->getRepository(GuildEvent::class)->find($foreignEvent->getId()));
        self::assertNotNull($this->em($client)->getRepository(GuildRank::class)->find($foreignRank->getId()));
    }

    public function testApplicationDecisionIsFinalAndCreatesOnlyOneMember(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::GAMING]);
        [$guild] = $this->guilds($client);

        $application = (new GuildApplication())
            ->setGuild($guild)
            ->setApplicantName('Applicant')
            ->setEmail('applicant-'.bin2hex(random_bytes(4)).'@example.test')
            ->setCharacterName('Single conversion')
            ->setMessage('This application contains enough text for the validation contract.');
        $this->em($client)->persist($application);
        $this->em($client)->flush();
        $client->loginUser($admin);

        $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('application-'.$application->getId())
            ->getValue();

        $client->request('POST', '/admin/gaming/applications/'.$application->getId().'/accept', ['_token' => $token]);
        self::assertResponseRedirects('/admin/gaming/applications');

        $client->request('POST', '/admin/gaming/applications/'.$application->getId().'/reject', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $this->em($client)->clear();
        $stored = $this->em($client)->getRepository(GuildApplication::class)->find($application->getId());
        self::assertInstanceOf(GuildApplication::class, $stored);
        self::assertSame(GuildApplication::STATUS_ACCEPTED, $stored->getStatus());
        self::assertNotNull($stored->getConvertedMember());
        self::assertSame(
            1,
            $this->em($client)->getRepository(GuildMember::class)->count([
                'guild' => $guild,
                'characterName' => 'Single conversion',
            ]),
        );
    }

    public function testInvalidEventRulesAreRejectedWithoutPersistence(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::GAMING]);
        [$guild] = $this->guilds($client);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/gaming/guild/'.$guild->getId().'/collaboration/event/new');
        $form = $crawler->selectButton('Speichern')->form([
            'guild_event[title]' => 'Invalid event',
            'guild_event[type]' => 'raid',
            'guild_event[description]' => 'The end is deliberately before the start.',
            'guild_event[startsAt]' => '2030-01-02T20:00',
            'guild_event[endsAt]' => '2030-01-02T19:00',
            'guild_event[maxParticipants]' => '10',
            'guild_event[location]' => 'Test',
            'guild_event[status]' => 'planned',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Das Ende muss nach dem Beginn liegen.');
        self::assertSame(0, $this->em($client)->getRepository(GuildEvent::class)->count([
            'guild' => $guild,
            'title' => 'Invalid event',
        ]));
    }

    public function testRequiredExternalNotificationFailureKeepsInternalNotificationEvidence(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::GAMING]);
        $memberUser = $this->user($client);
        [$guild] = $this->guilds($client);

        $member = (new GuildMember())
            ->setGuild($guild)
            ->setUser($memberUser)
            ->setCharacterName('Notification recipient')
            ->setActive(true);
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS)
            ->setTargetKey('guild-discord-'.bin2hex(random_bytes(4)))
            ->setProviderKey(DiscordGuildNotificationConnectorAdapter::PROVIDER_KEY)
            ->setDisplayName('Required guild Discord')
            ->setRequired(true)
            ->setPriority(1)
            ->setConfigurationReference(DiscordGuildNotificationConnectorAdapter::CONFIGURATION_REFERENCE)
            ->setEnabled(true);

        $this->em($client)->persist($member);
        $this->em($client)->persist($target);
        $this->em($client)->flush();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/gaming/guild/'.$guild->getId().'/collaboration/event/new');
        $form = $crawler->selectButton('Speichern')->form([
            'guild_event[title]' => 'Notification evidence',
            'guild_event[type]' => 'raid',
            'guild_event[description]' => 'External delivery is expected to fail closed.',
            'guild_event[startsAt]' => '2030-02-01T20:00',
            'guild_event[endsAt]' => '2030-02-01T22:00',
            'guild_event[maxParticipants]' => '10',
            'guild_event[location]' => 'Test',
            'guild_event[status]' => 'planned',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/gaming/guild/'.$guild->getId().'/collaboration');
        self::assertSame(1, $this->em($client)->getRepository(GuildEvent::class)->count([
            'guild' => $guild,
            'title' => 'Notification evidence',
        ]));

        $notification = $this->em($client)->getRepository(MemberNotification::class)->findOneBy([
            'guild' => $guild,
            'user' => $memberUser,
            'type' => 'guild_event',
        ]);
        self::assertInstanceOf(MemberNotification::class, $notification);
        self::assertSame('Neuer Termin: Notification evidence', $notification->getTitle());
        self::assertSame('/guild-area/'.$guild->getId(), $notification->getLink());
    }

    private function user(KernelBrowser $client, array $permissions = []): User
    {
        $user = (new User())
            ->setEmail('guild-continuous-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Guild continuous test')
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
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild A '.$suffix)
            ->setSlug('guild-a-'.$suffix)
            ->setServerName('Server')
            ->setDescription('Guild A');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Guild B '.$suffix)
            ->setSlug('guild-b-'.$suffix)
            ->setServerName('Server')
            ->setDescription('Guild B');

        $this->em($client)->persist($game);
        $this->em($client)->persist($guild);
        $this->em($client)->persist($otherGuild);
        $this->em($client)->flush();

        return [$guild, $otherGuild];
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
