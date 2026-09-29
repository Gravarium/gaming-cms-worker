<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GuildCalendarSecurityTest extends WebTestCase
{
    public function testCalendarIsPrivateAndEventTextCannotInjectIcsLines(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        $guild = $this->guild($client);
        $member = (new GuildMember())
            ->setGuild($guild)
            ->setUser($user)
            ->setCharacterName('Calendar member');
        $event = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle("Planned raid\r\nEND:VEVENT\r\nBEGIN:VTODO\r\nSUMMARY:Injected")
            ->setDescription("Bring supplies\r\nBEGIN:VEVENT\r\nSUMMARY:Injected description")
            ->setLocation("Room;1\r\nATTENDEE:attacker@example.test");
        $this->entityManager($client)->persist($member);
        $this->entityManager($client)->persist($event);
        $this->entityManager($client)->flush();
        $client->loginUser($user);

        $client->request('GET', '/guild-area/'.$guild->getId().'/calendar.ics');

        self::assertResponseIsSuccessful();
        self::assertSame('text/calendar; charset=utf-8', $client->getResponse()->headers->get('Content-Type'));
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SUMMARY:Planned raid\\nEND:VEVENT\\nBEGIN:VTODO\\nSUMMARY:Injected', $body);
        self::assertStringContainsString('DESCRIPTION:Bring supplies\\nBEGIN:VEVENT\\nSUMMARY:Injected description', $body);
        self::assertStringContainsString('LOCATION:Room\\;1\\nATTENDEE:attacker@example.test', $body);
        self::assertStringNotContainsString("\r\nBEGIN:VTODO\r\n", $body);
        self::assertStringNotContainsString("\r\nATTENDEE:attacker@example.test\r\n", $body);
    }

    public function testNonMemberCannotReadGuildCalendar(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        $guild = $this->guild($client);
        $client->loginUser($user);

        $client->request('GET', '/guild-area/'.$guild->getId().'/calendar.ics');

        self::assertResponseStatusCodeSame(403);
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('guild-calendar-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Guild calendar member')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function guild(KernelBrowser $client): Guild
    {
        $suffix = bin2hex(random_bytes(4));
        $game = (new Game())->setName('Calendar game '.$suffix)->setSlug('calendar-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Calendar guild '.$suffix)
            ->setSlug('calendar-guild-'.$suffix)
            ->setServerName('Calendar server')
            ->setDescription('Calendar access regression');

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->flush();

        return $guild;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
