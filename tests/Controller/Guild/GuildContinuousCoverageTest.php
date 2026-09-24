<?php

declare(strict_types=1);

namespace App\Tests\Controller\Guild;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\GuildEvent;
use App\Entity\GuildMember;
use App\Entity\GuildRank;
use App\Entity\MemberNotification;
use App\Entity\User;
use App\ExternalConnector\DiscordGuildNotificationConnectorAdapter;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalNotificationMessage;
use App\ExternalConnector\GuildNotificationRecipientResolver;
use App\Service\GuildWebhookSender;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

        $crawler = $client->request('GET', '/admin/gaming/applications');
        $token = $crawler
            ->filter('form[action="/admin/gaming/applications/'.$application->getId().'/accept"] input[name="_token"]')
            ->attr('value');

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

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Das Ende muss nach dem Beginn liegen.');
        self::assertSame(0, $this->em($client)->getRepository(GuildEvent::class)->count([
            'guild' => $guild,
            'title' => 'Invalid event',
        ]));
    }

    public function testRequiredExternalNotificationFailureKeepsInternalNotificationEvidence(): void
    {
        $client = static::createClient();
        $memberUser = $this->user($client);
        [$guild] = $this->guilds($client);

        $notification = (new MemberNotification())
            ->setUser($memberUser)
            ->setGuild($guild)
            ->setType('guild_event')
            ->setTitle('Neuer Termin: Notification evidence')
            ->setMessage('External delivery is expected to fail closed.')
            ->setLink('/guild-area/'.$guild->getId());
        $this->em($client)->persist($notification);
        $this->em($client)->flush();
        $notificationId = $notification->getId();
        self::assertNotNull($notificationId);

        $resolver = new class($guild) implements GuildNotificationRecipientResolver {
            public function __construct(private readonly Guild $guild) {}
            public function resolve(string $recipientReference): ?Guild
            {
                return $recipientReference === 'guild:'.$this->guild->getId() ? $this->guild : null;
            }
        };
        $sender = new class implements GuildWebhookSender {
            public function notify(Guild $guild, string $type, string $title, string $message): bool
            {
                return false;
            }
        };
        $adapter = new DiscordGuildNotificationConnectorAdapter($resolver, $sender);
        $target = new ExternalConnectorTargetDefinition(
            'notifications',
            'guild-discord-test',
            DiscordGuildNotificationConnectorAdapter::PROVIDER_KEY,
            'Guild Discord',
            true,
            1,
            DiscordGuildNotificationConnectorAdapter::CONFIGURATION_REFERENCE,
        );

        try {
            $adapter->send(
                $target,
                new ExternalNotificationMessage(
                    'guild_event',
                    'Neuer Termin: Notification evidence',
                    'External delivery is expected to fail closed.',
                    '/guild-area/'.$guild->getId(),
                    'guild:'.$guild->getId(),
                ),
            );
            self::fail('Required external delivery should fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Discord notification delivery did not succeed.', $exception->getMessage());
        }

        $this->em($client)->clear();
        $stored = $this->em($client)->find(MemberNotification::class, $notificationId);
        self::assertInstanceOf(MemberNotification::class, $stored);
        self::assertSame('guild_event', $stored->getType());
        self::assertSame('Neuer Termin: Notification evidence', $stored->getTitle());
        self::assertSame('/guild-area/'.$guild->getId(), $stored->getLink());
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
