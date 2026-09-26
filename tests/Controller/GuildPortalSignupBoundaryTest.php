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

final class GuildPortalSignupBoundaryTest extends WebTestCase
{
    public function testOversizedNoteLeavesExistingSignupUnchanged(): void
    {
        $client = static::createClient();
        $fixture = $this->createFixture($client);
        $client->loginUser($fixture['user']);

        $client->request('POST', $this->signupPath($fixture), [
            '_token' => $this->csrfToken($client, $fixture),
            'member' => (string) $fixture['memberId'],
            'response' => GuildEventSignup::GOING,
            'role' => 'damage',
            'note' => str_repeat('x', 501),
        ]);

        self::assertResponseRedirects('/guild-area/'.$fixture['guildId']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Die Notiz darf höchstens 500 Zeichen enthalten. Deine Anmeldung wurde nicht geändert.');

        $this->em($client)->clear();
        $stored = $this->em($client)->find(GuildEventSignup::class, $fixture['signupId']);
        self::assertInstanceOf(GuildEventSignup::class, $stored);
        self::assertSame(GuildEventSignup::MAYBE, $stored->getResponse());
        self::assertSame('Original note', $stored->getNote());
        self::assertSame('support', $stored->getRole());
    }

    public function testNonStringNoteLeavesExistingSignupUnchanged(): void
    {
        $client = static::createClient();
        $fixture = $this->createFixture($client);
        $client->loginUser($fixture['user']);

        $client->request('POST', $this->signupPath($fixture), [
            '_token' => $this->csrfToken($client, $fixture),
            'member' => (string) $fixture['memberId'],
            'response' => GuildEventSignup::GOING,
            'role' => 'damage',
            'note' => ['unexpected' => 'value'],
        ]);

        self::assertResponseRedirects('/guild-area/'.$fixture['guildId']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Die Notiz darf höchstens 500 Zeichen enthalten. Deine Anmeldung wurde nicht geändert.');

        $this->em($client)->clear();
        $stored = $this->em($client)->find(GuildEventSignup::class, $fixture['signupId']);
        self::assertInstanceOf(GuildEventSignup::class, $stored);
        self::assertSame(GuildEventSignup::MAYBE, $stored->getResponse());
        self::assertSame('Original note', $stored->getNote());
        self::assertSame('support', $stored->getRole());
    }

    public function testFiveHundredCharacterNoteCanBeSaved(): void
    {
        $client = static::createClient();
        $fixture = $this->createFixture($client);
        $client->loginUser($fixture['user']);
        $note = str_repeat('n', 500);

        $client->request('POST', $this->signupPath($fixture), [
            '_token' => $this->csrfToken($client, $fixture),
            'member' => (string) $fixture['memberId'],
            'response' => GuildEventSignup::GOING,
            'role' => 'damage',
            'note' => $note,
        ]);

        self::assertResponseRedirects('/guild-area/'.$fixture['guildId']);
        $client->followRedirect();

        $this->em($client)->clear();
        $stored = $this->em($client)->find(GuildEventSignup::class, $fixture['signupId']);
        self::assertInstanceOf(GuildEventSignup::class, $stored);
        self::assertSame(GuildEventSignup::GOING, $stored->getResponse());
        self::assertSame('damage', $stored->getRole());
        self::assertSame($note, $stored->getNote());
    }

    /**
     * @return array{user:User,guildId:int,eventId:int,memberId:int,signupId:int}
     */
    private function createFixture(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(6));
        $user = (new User())
            ->setEmail('guild-signup-'.$suffix.'@example.test')
            ->setDisplayName('Guild signup test')
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $game = (new Game())
            ->setName('WCP 345 test game')
            ->setSlug('wcp-345-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('WCP 345 test guild')
            ->setSlug('wcp-345-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Guild signup note boundary test');
        $member = (new GuildMember())
            ->setGuild($guild)
            ->setUser($user)
            ->setCharacterName('WCP 345 character');
        $event = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('WCP 345 event')
            ->setDescription('Guild signup test event')
            ->setStartsAt(new \DateTimeImmutable('+1 day'));
        $signup = (new GuildEventSignup())
            ->setEvent($event)
            ->setMember($member)
            ->setUser($user)
            ->setResponse(GuildEventSignup::MAYBE)
            ->setRole('support')
            ->setNote('Original note');

        $entityManager = $this->em($client);
        foreach ([$user, $game, $guild, $member, $event, $signup] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $guildId = $guild->getId();
        $eventId = $event->getId();
        $memberId = $member->getId();
        $signupId = $signup->getId();
        if ($guildId === null || $eventId === null || $memberId === null || $signupId === null) {
            throw new \LogicException('Could not persist guild signup fixture.');
        }

        return [
            'user' => $user,
            'guildId' => $guildId,
            'eventId' => $eventId,
            'memberId' => $memberId,
            'signupId' => $signupId,
        ];
    }

    /** @param array{guildId:int,eventId:int} $fixture */
    private function csrfToken(KernelBrowser $client, array $fixture): string
    {
        $crawler = $client->request('GET', '/guild-area/'.$fixture['guildId']);
        self::assertResponseIsSuccessful();

        return (string) $crawler
            ->filter('form[action="'.$this->signupPath($fixture).'"] input[name="_token"]')
            ->attr('value');
    }

    /** @param array{guildId:int,eventId:int} $fixture */
    private function signupPath(array $fixture): string
    {
        return '/guild-area/'.$fixture['guildId'].'/event/'.$fixture['eventId'].'/signup';
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
