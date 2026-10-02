<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GuildEventWaitlistPromotionTest extends WebTestCase
{
    public function testGuildSignupPromotesOldestEligibleWaitlistedMemberOnly(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        [$guild, $event] = $this->event($em);
        $owner = $this->user($em);
        $skippedUser = $this->user($em);
        $firstUser = $this->user($em);
        $secondUser = $this->user($em);
        $ownerMember = $this->member($em, $guild, $owner, 'Owner');
        $skippedMember = $this->member($em, $guild, $skippedUser, 'Inactive')->setActive(false);
        $firstMember = $this->member($em, $guild, $firstUser, 'First eligible');
        $secondMember = $this->member($em, $guild, $secondUser, 'Second eligible');
        $confirmed = $this->signup($em, $event, $ownerMember, $owner, GuildEventSignup::GOING);
        $skipped = $this->signup($em, $event, $skippedMember, $skippedUser, GuildEventSignup::WAITLIST);
        $first = $this->signup($em, $event, $firstMember, $firstUser, GuildEventSignup::WAITLIST);
        $second = $this->signup($em, $event, $secondMember, $secondUser, GuildEventSignup::WAITLIST);
        $em->flush();
        $ids = array_map(static fn (GuildEventSignup $signup): int => (int) $signup->getId(), [$confirmed, $skipped, $first, $second]);
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/guild-area/'.$guild->getId());
        $token = $crawler->filter('form[action="/guild-area/'.$guild->getId().'/event/'.$event->getId().'/signup"] input[name="_token"]')->attr('value');
        $client->request('POST', '/guild-area/'.$guild->getId().'/event/'.$event->getId().'/signup', [
            '_token' => $token, 'member' => $ownerMember->getId(), 'response' => GuildEventSignup::DECLINED, 'role' => 'other',
        ]);

        self::assertResponseRedirects('/guild-area/'.$guild->getId());
        $em->clear();
        self::assertSame(GuildEventSignup::DECLINED, $em->find(GuildEventSignup::class, $ids[0])?->getResponse());
        self::assertSame(GuildEventSignup::WAITLIST, $em->find(GuildEventSignup::class, $ids[1])?->getResponse());
        self::assertSame(GuildEventSignup::GOING, $em->find(GuildEventSignup::class, $ids[2])?->getResponse());
        self::assertSame(GuildEventSignup::WAITLIST, $em->find(GuildEventSignup::class, $ids[3])?->getResponse());
        self::assertSame(1, $em->getRepository(GuildEventSignup::class)->count(['event' => $event->getId(), 'response' => GuildEventSignup::GOING]));
    }

    public function testPortalUpdatePromotesWaitlistAfterConfirmedMemberChangesToMaybe(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        [$guild, $event] = $this->event($em);
        $owner = $this->user($em);
        $nextUser = $this->user($em);
        $confirmed = $this->signup($em, $event, $this->member($em, $guild, $owner, 'Owner'), $owner, GuildEventSignup::GOING);
        $next = $this->signup($em, $event, $this->member($em, $guild, $nextUser, 'Next'), $nextUser, GuildEventSignup::WAITLIST);
        $em->flush();
        $confirmedId = (int) $confirmed->getId();
        $nextId = (int) $next->getId();
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/guild-area/event-signups');
        $token = $crawler->filter('form[action="/guild-area/event-signups/'.$confirmedId.'/update"] input[name="_token"]')->attr('value');
        $client->request('POST', '/guild-area/event-signups/'.$confirmedId.'/update', [
            '_token' => $token, 'response' => GuildEventSignup::MAYBE, 'role' => 'other',
        ]);

        self::assertResponseRedirects('/guild-area/event-signups');
        $em->clear();
        self::assertSame(GuildEventSignup::MAYBE, $em->find(GuildEventSignup::class, $confirmedId)?->getResponse());
        self::assertSame(GuildEventSignup::GOING, $em->find(GuildEventSignup::class, $nextId)?->getResponse());
        self::assertSame(1, $em->getRepository(GuildEventSignup::class)->count(['event' => $event->getId(), 'response' => GuildEventSignup::GOING]));
    }

    /** @return array{Guild, GuildEvent} */
    private function event(EntityManagerInterface $em): array
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())->setName('Waitlist game '.$suffix)->setSlug('waitlist-game-'.$suffix);
        $guild = (new Guild())->setGame($game)->setName('Waitlist guild '.$suffix)
            ->setSlug('waitlist-guild-'.$suffix)->setServerName('Server')->setDescription('Waitlist test');
        $event = (new GuildEvent())->setGuild($guild)->setTitle('Waitlist raid')->setDescription('One place')->setMaxParticipants(1);
        foreach ([$game, $guild, $event] as $entity) { $em->persist($entity); }
        $em->flush();

        return [$guild, $event];
    }

    private function user(EntityManagerInterface $em): User
    {
        $user = (new User())->setEmail('waitlist-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Waitlist member')->verifyEmail();
        $em->persist($user);
        return $user;
    }

    private function member(EntityManagerInterface $em, Guild $guild, User $user, string $name): GuildMember
    {
        $member = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName($name);
        $em->persist($member);
        return $member;
    }

    private function signup(EntityManagerInterface $em, GuildEvent $event, GuildMember $member, User $user, string $response): GuildEventSignup
    {
        $signup = (new GuildEventSignup())->setEvent($event)->setMember($member)->setUser($user)->setResponse($response);
        $em->persist($signup);
        return $signup;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
