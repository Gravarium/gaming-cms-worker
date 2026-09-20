<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\GuildApplicationQuestion;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class GamingGuildSecurityTest extends WebTestCase
{
    public function testGamingPermissionIsRequiredServerSide(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($user);

        $client->request('GET', '/admin/gaming');
        self::assertResponseStatusCodeSame(403);
    }

    public function testMemberRoutesRejectForeignGuildResources(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::GAMING]);
        [$guild, $otherGuild] = $this->guilds($client);
        $member = (new GuildMember())->setGuild($otherGuild)->setCharacterName('Foreign');
        $this->em($client)->persist($member);
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/gaming/guild/'.$guild->getId().'/members/'.$member->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMemberDeleteRequiresCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::GAMING]);
        [$guild] = $this->guilds($client);
        $member = (new GuildMember())->setGuild($guild)->setCharacterName('Protected');
        $this->em($client)->persist($member);
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('POST', '/admin/gaming/guild/'.$guild->getId().'/members/'.$member->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em($client)->getRepository(GuildMember::class)->find($member->getId()));
    }

    public function testPortalRejectsEventFromAnotherGuild(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        [$guild, $otherGuild] = $this->guilds($client);
        $member = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Owned');
        $event = (new GuildEvent())->setGuild($otherGuild)->setTitle('Foreign')->setDescription('Foreign event');
        $this->em($client)->persist($member);
        $this->em($client)->persist($event);
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('POST', '/guild-area/'.$guild->getId().'/event/'.$event->getId().'/signup');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPortalRejectsSignupForCancelledEvent(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        [$guild] = $this->guilds($client);
        $member = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Owned');
        $event = (new GuildEvent())->setGuild($guild)->setTitle('Cancelled')->setDescription('Cancelled event')->setStatus(GuildEvent::STATUS_CANCELLED);
        $this->em($client)->persist($member);
        $this->em($client)->persist($event);
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('POST', '/guild-area/'.$guild->getId().'/event/'.$event->getId().'/signup');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRequiredApplicationQuestionIsValidatedServerSide(): void
    {
        $client = static::createClient();
        [$guild] = $this->guilds($client);
        $guild->setRecruitmentOpen(true);
        $question = (new GuildApplicationQuestion())->setGuild($guild)->setLabel('Warum?')->setRequired(true);
        $this->em($client)->persist($question);
        $this->em($client)->flush();

        $crawler = $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/apply');
        $form = $crawler->selectButton('Bewerbung absenden')->form([
            'guild_application[applicantName]' => 'Applicant',
            'guild_application[email]' => 'applicant@example.test',
            'guild_application[characterName]' => 'Character',
            'guild_application[message]' => 'Dies ist eine ausreichend lange Bewerbung.',
            'guild_application[question_'.$question->getId().']' => '',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Bitte beantworte dieses Pflichtfeld.');
        self::assertSame(0, $this->em($client)->getRepository(GuildApplication::class)->count(['guild' => $guild]));
    }

    public function testPortalAllowsOwnedMemberToSignupForPlannedEvent(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        [$guild] = $this->guilds($client);
        $member = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Owned');
        $event = (new GuildEvent())->setGuild($guild)->setTitle('Raid')->setDescription('Planned raid');
        $this->em($client)->persist($member);
        $this->em($client)->persist($event);
        $this->em($client)->flush();
        $client->loginUser($user);

        $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken('event-signup-'.$event->getId())->getValue();
        $client->request('POST', '/guild-area/'.$guild->getId().'/event/'.$event->getId().'/signup', [
            '_token' => $token,
            'member' => $member->getId(),
            'response' => GuildEventSignup::GOING,
            'role' => 'damage',
        ]);

        self::assertResponseRedirects('/guild-area/'.$guild->getId());
        $signup = $this->em($client)->getRepository(GuildEventSignup::class)->findOneBy(['event' => $event, 'member' => $member]);
        self::assertInstanceOf(GuildEventSignup::class, $signup);
        self::assertSame(GuildEventSignup::GOING, $signup->getResponse());
        self::assertSame('damage', $signup->getRole());
    }

    public function testPortalRejectsOwnedCharacterFromAnotherGuild(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        [$guild, $otherGuild] = $this->guilds($client);
        $member = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Local');
        $foreignMember = (new GuildMember())->setGuild($otherGuild)->setUser($user)->setCharacterName('Foreign');
        $event = (new GuildEvent())->setGuild($guild)->setTitle('Raid')->setDescription('Planned raid');
        foreach ([$member, $foreignMember, $event] as $entity) { $this->em($client)->persist($entity); }
        $this->em($client)->flush();
        $client->loginUser($user);

        $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken('event-signup-'.$event->getId())->getValue();
        $client->request('POST', '/guild-area/'.$guild->getId().'/event/'.$event->getId().'/signup', [
            '_token' => $token,
            'member' => $foreignMember->getId(),
            'response' => GuildEventSignup::GOING,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function user(KernelBrowser $client, array $permissions = []): User
    {
        $user = (new User())
            ->setEmail('gaming-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Gaming test')
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
        $otherGuild = (new Guild())->setGame($game)->setName('Guild B '.$suffix)->setSlug('guild-b-'.$suffix)->setServerName('Server')->setDescription('Guild B');
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
