<?php

declare(strict_types=1);

namespace App\Tests\Controller\Guild;

use App\Entity\AuditLog;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GuildCharacterProfileTest extends WebTestCase
{
    public function testMemberCanFollowThePortalLinkAndEditOnlyNameAndClass(): void
    {
        $client = static::createClient();
        $owner = $this->user($client);
        $guild = $this->guild($client, 'profile');
        $member = $this->member($client, $guild, $owner, 'Old character');

        $guildId = $guild->getId();
        $memberId = $member->getId();
        self::assertNotNull($guildId);
        self::assertNotNull($memberId);

        $client->loginUser($owner);
        $portal = $client->request('GET', '/guild-area/'.$guildId);
        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $portal->filter('a[href="/guild-area/'.$guildId.'/character/'.$memberId.'/edit"]')->count(),
        );

        $path = $this->editPath($guildId, $memberId);
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('120', $crawler->filter('input[name="guild_character_profile[characterName]"]')->attr('maxlength'));
        self::assertSame('100', $crawler->filter('input[name="guild_character_profile[characterClass]"]')->attr('maxlength'));

        foreach (['rank', 'rankName', 'characterLevel', 'playerName', 'leader', 'active', 'position', 'user', 'guild'] as $field) {
            self::assertSame(
                0,
                $crawler->filter('form [name="guild_character_profile['.$field.']"]')->count(),
                'The member form must not expose the '.$field.' field.',
            );
        }

        $newName = str_repeat('界', 120);
        $newClass = str_repeat('Mage', 25);
        $form = $crawler->selectButton('Änderungen speichern')->form([
            'guild_character_profile[characterName]' => $newName,
            'guild_character_profile[characterClass]' => $newClass,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/guild-area/'.$guildId);
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));

        $stored = $this->reloadMember($client, $memberId);
        self::assertSame($newName, $stored->getCharacterName());
        self::assertSame($newClass, $stored->getCharacterClass());
        self::assertSame('Officer', $stored->getRankName());
        self::assertSame(42, $stored->getCharacterLevel());
        self::assertSame('Player One', $stored->getPlayerName());
        self::assertTrue($stored->isLeader());
        self::assertTrue($stored->isActive());
        self::assertSame(2, $stored->getPosition());
        self::assertSame($owner->getId(), $stored->getUser()?->getId());
        self::assertSame($guildId, $stored->getGuild()?->getId());

        $audit = $this->entityManager($client)->getRepository(AuditLog::class)->findOneBy(
            ['action' => 'guild.character_profile.updated'],
            ['id' => 'DESC'],
        );
        self::assertInstanceOf(AuditLog::class, $audit);
        self::assertSame('Eigenes Gildencharakterprofil aktualisiert.', $audit->getSummary());
        self::assertSame([], $audit->getContext());
        self::assertStringNotContainsString($newName, $audit->getSummary());
        self::assertStringNotContainsString($newClass, $audit->getSummary());
    }

    public function testMissingOrInvalidCsrfAndForgedPrivilegedFieldsDoNotPersist(): void
    {
        $client = static::createClient();
        $owner = $this->user($client);
        $guild = $this->guild($client, 'csrf');
        $member = $this->member($client, $guild, $owner, 'Protected character');
        $guildId = $guild->getId();
        $memberId = $member->getId();
        self::assertNotNull($guildId);
        self::assertNotNull($memberId);

        $client->loginUser($owner);
        $path = $this->editPath($guildId, $memberId);

        $client->request('POST', $path, [
            'guild_character_profile' => [
                'characterName' => 'Missing token change',
                'characterClass' => 'Healer',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->assertOriginalProfile($client, $memberId);

        $client->request('POST', $path, [
            'guild_character_profile' => [
                '_token' => 'invalid-csrf-token',
                'characterName' => 'Invalid token change',
                'characterClass' => 'Healer',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->assertOriginalProfile($client, $memberId);

        $crawler = $client->request('GET', $path);
        $token = $crawler->filter('input[name="guild_character_profile[_token]"]')->attr('value');
        $client->request('POST', $path, [
            'guild_character_profile' => [
                '_token' => $token,
                'characterName' => 'Forged character',
                'characterClass' => 'Healer',
                'rank' => '999',
                'rankName' => 'Administrator',
                'characterLevel' => '99',
                'playerName' => 'Forged player',
                'leader' => '1',
                'active' => '0',
                'position' => '-1',
                'guild' => '999',
                'user' => '999',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->assertOriginalProfile($client, $memberId);
    }

    public function testInvalidNamesAndClassesAreRejectedWithoutPersistence(): void
    {
        $client = static::createClient();
        $owner = $this->user($client);
        $guild = $this->guild($client, 'validation');
        $member = $this->member($client, $guild, $owner, 'Validated character');
        $guildId = $guild->getId();
        $memberId = $member->getId();
        self::assertNotNull($guildId);
        self::assertNotNull($memberId);

        $client->loginUser($owner);
        $path = $this->editPath($guildId, $memberId);
        $invalidSubmissions = [
            ['characterName' => '   ', 'characterClass' => 'Changed'],
            ['characterName' => str_repeat('界', 121), 'characterClass' => 'Changed'],
            ['characterName' => 'Changed', 'characterClass' => str_repeat('術', 101)],
        ];

        foreach ($invalidSubmissions as $values) {
            $crawler = $client->request('GET', $path);
            $form = $crawler->selectButton('Änderungen speichern')->form([
                'guild_character_profile[characterName]' => $values['characterName'],
                'guild_character_profile[characterClass]' => $values['characterClass'],
            ]);
            $client->submit($form);

            self::assertResponseStatusCodeSame(422);
            $this->assertOriginalProfile($client, $memberId);
        }
    }

    public function testOnlyAnActiveCharacterOwnedByTheCurrentUserInTheRouteGuildCanBeEdited(): void
    {
        $client = static::createClient();
        $owner = $this->user($client);
        $otherUser = $this->user($client);
        $guild = $this->guild($client, 'owner');
        $otherGuild = $this->guild($client, 'other');
        $owned = $this->member($client, $guild, $owner, 'Owned character');
        $foreign = $this->member($client, $guild, $otherUser, 'Foreign character');
        $crossGuild = $this->member($client, $otherGuild, $owner, 'Other guild character');
        $inactive = $this->member($client, $guild, $owner, 'Inactive character', active: false);

        $guildId = $guild->getId();
        $ownedId = $owned->getId();
        $foreignId = $foreign->getId();
        $crossGuildId = $crossGuild->getId();
        $inactiveId = $inactive->getId();
        self::assertNotNull($guildId);
        self::assertNotNull($ownedId);
        self::assertNotNull($foreignId);
        self::assertNotNull($crossGuildId);
        self::assertNotNull($inactiveId);

        $client->loginUser($owner);
        $client->request('GET', $this->editPath($guildId, $ownedId));
        self::assertResponseIsSuccessful();

        foreach ([$foreignId, $crossGuildId, $inactiveId] as $deniedId) {
            $client->request('GET', $this->editPath($guildId, $deniedId));
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('POST', $this->editPath($guildId, $foreignId), [
            'guild_character_profile' => [
                'characterName' => 'Unauthorized change',
                'characterClass' => 'Healer',
            ],
        ]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame('Foreign character', $this->reloadMember($client, $foreignId)->getCharacterName());
        self::assertSame('Other guild character', $this->reloadMember($client, $crossGuildId)->getCharacterName());
        self::assertSame('Inactive character', $this->reloadMember($client, $inactiveId)->getCharacterName());
    }

    public function testEditorRequiresAuthentication(): void
    {
        $client = static::createClient();
        $owner = $this->user($client);
        $guild = $this->guild($client, 'anonymous');
        $member = $this->member($client, $guild, $owner, 'Private character');
        $guildId = $guild->getId();
        $memberId = $member->getId();
        self::assertNotNull($guildId);
        self::assertNotNull($memberId);

        $client->request('GET', $this->editPath($guildId, $memberId));

        self::assertResponseRedirects('/login');
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('guild-character-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Guild profile member')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function guild(KernelBrowser $client, string $label): Guild
    {
        $suffix = bin2hex(random_bytes(4));
        $game = (new Game())
            ->setName('Guild profile game '.$suffix)
            ->setSlug('guild-profile-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild '.$label.' '.$suffix)
            ->setSlug('guild-'.$label.'-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Guild character profile test.');

        $this->entityManager($client)->persist($game);
        $this->entityManager($client)->persist($guild);
        $this->entityManager($client)->flush();

        return $guild;
    }

    private function member(
        KernelBrowser $client,
        Guild $guild,
        User $owner,
        string $name,
        bool $active = true,
    ): GuildMember {
        $member = (new GuildMember())
            ->setGuild($guild)
            ->setUser($owner)
            ->setCharacterName($name)
            ->setCharacterClass('Mage')
            ->setRankName('Officer')
            ->setCharacterLevel(42)
            ->setPlayerName('Player One')
            ->setLeader(true)
            ->setActive($active)
            ->setPosition(2);
        $this->entityManager($client)->persist($member);
        $this->entityManager($client)->flush();

        return $member;
    }

    private function editPath(int $guildId, int $memberId): string
    {
        return '/guild-area/'.$guildId.'/character/'.$memberId.'/edit';
    }

    private function assertOriginalProfile(KernelBrowser $client, int $memberId): void
    {
        $member = $this->reloadMember($client, $memberId);
        self::assertSame('Protected character', $member->getCharacterName());
        self::assertSame('Mage', $member->getCharacterClass());
        self::assertSame('Officer', $member->getRankName());
        self::assertSame(42, $member->getCharacterLevel());
        self::assertSame('Player One', $member->getPlayerName());
        self::assertTrue($member->isLeader());
        self::assertTrue($member->isActive());
        self::assertSame(2, $member->getPosition());
    }

    private function reloadMember(KernelBrowser $client, int $memberId): GuildMember
    {
        $this->entityManager($client)->clear();
        $member = $this->entityManager($client)->getRepository(GuildMember::class)->find($memberId);
        self::assertInstanceOf(GuildMember::class, $member);

        return $member;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
