<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildApplicationInboxTest extends WebTestCase
{
    private const INBOX_PATH = '/admin/gaming/applications/inbox';

    public function testExistingApplicationListLinksToTheInbox(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));

        $client->request('GET', '/admin/gaming/applications');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="'.self::INBOX_PATH.'"]');
    }

    public function testInboxRequiresTheGamingManagementPermission(): void
    {
        $anonymousClient = static::createClient();
        $anonymousClient->request('GET', self::INBOX_PATH);
        self::assertResponseRedirects('/login');

        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::CONTENT]));
        $client->request('GET', self::INBOX_PATH);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSearchFindsApplicantEmailCharacterAndGuild(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $targetGuild = $this->guild($client, 'Inbox Target Guild');
        $otherGuild = $this->guild($client, 'Inbox Other Guild');
        $target = $this->application($targetGuild, 'Inbox Target Applicant', 'target-mail-'.bin2hex(random_bytes(4)).'@example.test', 'Inbox Target Character');
        $other = $this->application($otherGuild, 'Inbox Other Applicant', 'other-mail-'.bin2hex(random_bytes(4)).'@example.test', 'Inbox Other Character');
        $this->em($client)->persist($target);
        $this->em($client)->persist($other);
        $this->em($client)->flush();

        $queries = [
            'Target Applicant',
            strstr($target->getEmail(), '@', true),
            'TARGET CHARACTER',
            $targetGuild->getName(),
        ];

        foreach ($queries as $query) {
            $client->request('GET', self::INBOX_PATH.'?'.http_build_query(['q' => $query]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('tbody', 'Inbox Target Applicant');
            self::assertSelectorTextNotContains('tbody', 'Inbox Other Applicant');
        }

        self::assertSelectorExists('a[href="/admin/gaming/applications/'.$target->getId().'/review"]');
    }

    public function testStatusFiltersApplyToEverySearchMatchAndCountResults(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::GAMING]);
        $guild = $this->guild($client, 'Inbox Status Guild');
        $pending = $this->application($guild, 'Inbox Status Pending', 'pending-'.bin2hex(random_bytes(4)).'@example.test', 'Pending Character');
        $reviewing = $this->application($guild, 'Inbox Status Reviewing', 'reviewing-'.bin2hex(random_bytes(4)).'@example.test', 'Reviewing Character');
        $reviewing->assignTo($manager);
        $accepted = $this->application($guild, 'Inbox Status Accepted', 'accepted-'.bin2hex(random_bytes(4)).'@example.test', 'Accepted Character');
        $accepted->accept();
        $rejected = $this->application($guild, 'Inbox Status Rejected', 'rejected-'.bin2hex(random_bytes(4)).'@example.test', 'Rejected Character');
        $rejected->reject();
        foreach ([$pending, $reviewing, $accepted, $rejected] as $application) {
            $this->em($client)->persist($application);
        }
        $this->em($client)->flush();
        $client->loginUser($manager);

        $client->request('GET', self::INBOX_PATH.'?'.http_build_query(['q' => 'Inbox Status', 'status' => 'open']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('p[aria-live="polite"]', '2 Treffer');
        self::assertSelectorTextContains('tbody', 'Inbox Status Pending');
        self::assertSelectorTextContains('tbody', 'Inbox Status Reviewing');
        self::assertSelectorTextNotContains('tbody', 'Inbox Status Accepted');
        self::assertSelectorTextNotContains('tbody', 'Inbox Status Rejected');

        $client->request('GET', self::INBOX_PATH.'?'.http_build_query(['q' => 'Inbox Status', 'status' => 'accepted']));
        self::assertSelectorTextContains('p[aria-live="polite"]', '1 Treffer');
        self::assertSelectorTextContains('tbody', 'Inbox Status Accepted');
        self::assertSelectorTextNotContains('tbody', 'Inbox Status Pending');

        $client->request('GET', self::INBOX_PATH.'?'.http_build_query(['q' => 'Inbox Status', 'status' => 'rejected']));
        self::assertSelectorTextContains('p[aria-live="polite"]', '1 Treffer');
        self::assertSelectorTextContains('tbody', 'Inbox Status Rejected');
    }

    public function testPaginationIsStableAndPreservesSearchAndStatus(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $guild = $this->guild($client, 'Inbox Pagination Guild');
        $createdAt = new \ReflectionProperty(GuildApplication::class, 'createdAt');
        $fixedTime = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        for ($index = 1; $index <= 27; ++$index) {
            $application = $this->application(
                $guild,
                sprintf('Inbox Page %02d', $index),
                sprintf('page-%02d-%s@example.test', $index, bin2hex(random_bytes(3))),
                sprintf('Page Character %02d', $index),
            );
            $createdAt->setValue($application, $fixedTime);
            $this->em($client)->persist($application);
        }
        $this->em($client)->flush();

        $crawler = $client->request('GET', self::INBOX_PATH.'?'.http_build_query(['q' => 'Inbox Page', 'status' => 'open']));
        self::assertResponseIsSuccessful();
        self::assertCount(25, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Inbox Page 27', $crawler->filter('tbody tr')->eq(0)->text());
        self::assertStringContainsString('Inbox Page 03', $crawler->filter('tbody tr')->eq(24)->text());

        $nextPage = $crawler->filter('a[aria-label="Nächste Seite"]')->attr('href');
        self::assertNotNull($nextPage);
        parse_str((string) parse_url($nextPage, PHP_URL_QUERY), $filters);
        self::assertSame('Inbox Page', $filters['q'] ?? null);
        self::assertSame('open', $filters['status'] ?? null);
        self::assertSame('2', $filters['page'] ?? null);

        $secondPage = $client->request('GET', $nextPage);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $secondPage->filter('tbody tr'));
        self::assertStringContainsString('Inbox Page 02', $secondPage->filter('tbody tr')->eq(0)->text());
        self::assertStringContainsString('Inbox Page 01', $secondPage->filter('tbody tr')->eq(1)->text());
    }

    public function testInboxIsPrivateUncachedAndOmitsApplicationAndInternalNotes(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $guild = $this->guild($client, 'Inbox Privacy Guild');
        $messageSecret = 'INBOX_MESSAGE_SECRET_'.bin2hex(random_bytes(4));
        $notesSecret = 'INBOX_NOTES_SECRET_'.bin2hex(random_bytes(4));
        $application = $this->application($guild, 'Inbox Privacy Applicant', 'privacy-'.bin2hex(random_bytes(4)).'@example.test', 'Privacy Character')
            ->setMessage($messageSecret)
            ->setInternalNotes($notesSecret);
        $this->em($client)->persist($application);
        $this->em($client)->flush();

        $client->request('GET', self::INBOX_PATH);

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        $body = $client->getResponse()->getContent();
        self::assertNotFalse($body);
        self::assertStringContainsString('Inbox Privacy Applicant', $body);
        self::assertStringNotContainsString($messageSecret, $body);
        self::assertStringNotContainsString($notesSecret, $body);
    }

    public function testMalformedFiltersFailClosedAndOutOfRangePageIsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));

        $invalidQueries = [
            '?q%5B%5D=unexpected',
            '?status=unknown',
            '?q='.str_repeat('x', 101),
            '?page=0',
            '?page=10001',
        ];
        foreach ($invalidQueries as $query) {
            $client->request('GET', self::INBOX_PATH.$query);
            self::assertResponseStatusCodeSame(400);
        }

        $client->request('GET', self::INBOX_PATH.'?'.http_build_query([
            'q' => 'No matching inbox application '.bin2hex(random_bytes(4)),
            'page' => 2,
        ]));
        self::assertResponseStatusCodeSame(404);
    }

    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('inbox-manager-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Inbox manager')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function guild(KernelBrowser $client, string $name): Guild
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Inbox game '.$suffix)->setSlug('inbox-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName($name.' '.$suffix)
            ->setSlug('inbox-guild-'.$suffix)
            ->setServerName('Inbox server')
            ->setDescription('Guild used by application inbox tests');
        $this->em($client)->persist($game);
        $this->em($client)->persist($guild);
        $this->em($client)->flush();

        return $guild;
    }

    private function application(Guild $guild, string $name, string $email, string $character): GuildApplication
    {
        return (new GuildApplication())
            ->setGuild($guild)
            ->setApplicantName($name)
            ->setEmail($email)
            ->setCharacterName($character)
            ->setMessage('This is a sufficiently long application message for the inbox test.');
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
