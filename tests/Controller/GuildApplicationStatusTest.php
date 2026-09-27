<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GuildApplicationStatusTest extends WebTestCase
{
    public function testApplicantSeesOnlyOwnStatusesAndNoPrivateApplicationContent(): void
    {
        $client = static::createClient();
        $applicant = $this->user($client);
        $reviewer = $this->user($client);
        [$guild, $otherGuild] = $this->guilds($client);
        $guild->setRecruitmentOpen(false);

        $pending = $this->application($guild, $applicant->getEmail(), 'My pending character')
            ->setInternalNotes('PRIVATE_INTERNAL_NOTE_MARKER')
            ->setAnswers([['question' => 'PRIVATE_QUESTION', 'answer' => 'PRIVATE_ANSWER_MARKER']]);
        $reviewing = $this->application($guild, $applicant->getEmail(), 'My reviewing character')->assignTo($reviewer);
        $accepted = $this->application($guild, $applicant->getEmail(), 'My accepted character');
        $accepted->accept();
        $rejected = $this->application($guild, $applicant->getEmail(), 'My rejected character');
        $rejected->reject();
        $foreign = $this->application($otherGuild, 'foreign-'.bin2hex(random_bytes(6)).'@example.test', 'FOREIGN_CHARACTER_MARKER')
            ->setInternalNotes('FOREIGN_PRIVATE_NOTE_MARKER');

        $em = $this->em($client);
        foreach ([$pending, $reviewing, $accepted, $rejected, $foreign] as $application) {
            $em->persist($application);
        }
        $em->flush();
        $client->loginUser($applicant);

        $client->request('GET', '/gaming/applications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Eingegangen');
        self::assertSelectorTextContains('body', 'Wird geprüft');
        self::assertSelectorTextContains('body', 'Angenommen');
        self::assertSelectorTextContains('body', 'Abgelehnt');
        self::assertSelectorTextContains('body', 'My pending character');
        self::assertSelectorTextContains('body', 'My accepted character');
        self::assertSelectorTextNotContains('body', 'FOREIGN_CHARACTER_MARKER');
        self::assertSelectorTextNotContains('body', 'FOREIGN_PRIVATE_NOTE_MARKER');
        self::assertSelectorTextNotContains('body', 'PRIVATE_INTERNAL_NOTE_MARKER');
        self::assertSelectorTextNotContains('body', 'PRIVATE_QUESTION');
        self::assertSelectorTextNotContains('body', 'PRIVATE_ANSWER_MARKER');
        self::assertStringNotContainsString($applicant->getEmail(), (string) $client->getResponse()->getContent());
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testStatusRouteRequiresAnAuthenticatedAccount(): void
    {
        $client = static::createClient();

        $client->request('GET', '/gaming/applications');

        self::assertResponseRedirects('/login');
    }

    public function testEmptyStateAndPublicGuildProfileLinkAreDiscoverable(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        [$guild] = $this->guilds($client);
        $client->loginUser($user);

        $client->request('GET', '/gaming/applications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Zu deinem Konto wurden noch keine Gildenbewerbungen gefunden.');
        self::assertSelectorExists('a[href="/gaming"]');

        $client->request('GET', '/gaming/guild/'.$guild->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/gaming/applications"]');
        self::assertSelectorTextContains('body', 'Meine Bewerbungen');
    }

    public function testHistoryUsesStablePagesAndClampsOutOfRangePage(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        [$guild] = $this->guilds($client);
        $em = $this->em($client);

        for ($index = 1; $index <= 26; ++$index) {
            $em->persist($this->application(
                $guild,
                $user->getEmail(),
                sprintf('Tracked character %02d', $index),
            ));
        }
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/gaming/applications?page=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount('tbody tr[data-application-row]', 25);
        self::assertSelectorExists('a[rel="next"][href="/gaming/applications?page=2"]');

        $client->request('GET', '/gaming/applications?page=99');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Seite 2 von 2');
        self::assertSelectorCount('tbody tr[data-application-row]', 1);

        $client->request('GET', '/gaming/applications?page[]=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Seite 1 von 2');
        self::assertSelectorCount('tbody tr[data-application-row]', 25);
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('guild-application-status-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Guild application status test')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    /** @return array{Guild, Guild} */
    private function guilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())
            ->setName('Application status game '.$suffix)
            ->setSlug('application-status-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Application status guild '.$suffix)
            ->setSlug('application-status-guild-'.$suffix)
            ->setServerName('Status server')
            ->setDescription('Guild application status test')
            ->setRecruitmentOpen(true);
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Other status guild '.$suffix)
            ->setSlug('other-status-guild-'.$suffix)
            ->setServerName('Other server')
            ->setDescription('Foreign application test')
            ->setRecruitmentOpen(true);

        $em = $this->em($client);
        $em->persist($game);
        $em->persist($guild);
        $em->persist($otherGuild);
        $em->flush();

        return [$guild, $otherGuild];
    }

    private function application(Guild $guild, string $email, string $character): GuildApplication
    {
        return (new GuildApplication())
            ->setGuild($guild)
            ->setApplicantName('Applicant '.$character)
            ->setEmail($email)
            ->setCharacterName($character)
            ->setCharacterClass('Mage')
            ->setMessage('Private application message for '.$character.'.');
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
