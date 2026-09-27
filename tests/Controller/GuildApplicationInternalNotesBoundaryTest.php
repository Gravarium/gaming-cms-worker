<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class GuildApplicationInternalNotesBoundaryTest extends WebTestCase
{
    public function testOverLimitUnicodeNotesAreRejectedBeforeMutationOrAudit(): void
    {
        $client = static::createClient();
        $reviewer = $this->reviewer($client);
        $application = $this->application($client, $reviewer);
        $applicationId = $application->getId();
        $reviewerId = $reviewer->getId();
        self::assertNotNull($applicationId);
        self::assertNotNull($reviewerId);
        $client->loginUser($reviewer);

        $url = '/admin/gaming/applications/'.$applicationId.'/review';
        $token = $this->reviewToken($client, $url);
        $entityManager = $this->entityManager($client);
        $auditCount = $entityManager->getRepository(AuditLog::class)->count(['action' => 'guild_application.review']);

        $client->request('POST', $url, [
            '_token' => $token,
            'internal_notes' => str_repeat('é', 10001),
            'release' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Interne Notizen dürfen höchstens 10.000 Zeichen enthalten.',
            $client->getResponse()->getContent(),
        );

        $entityManager->clear();
        $stored = $entityManager->find(GuildApplication::class, $applicationId);
        self::assertInstanceOf(GuildApplication::class, $stored);
        self::assertSame(GuildApplication::STATUS_REVIEWING, $stored->getStatus());
        self::assertSame($reviewerId, $stored->getAssignedTo()?->getId());
        self::assertSame('Existing review note', $stored->getInternalNotes());
        self::assertSame(
            $auditCount,
            $entityManager->getRepository(AuditLog::class)->count(['action' => 'guild_application.review']),
        );
    }

    public function testExactlyTenThousandUnicodeCharactersAreAccepted(): void
    {
        $client = static::createClient();
        $reviewer = $this->reviewer($client);
        $application = $this->application($client);
        $applicationId = $application->getId();
        $reviewerId = $reviewer->getId();
        self::assertNotNull($applicationId);
        self::assertNotNull($reviewerId);
        $client->loginUser($reviewer);

        $url = '/admin/gaming/applications/'.$applicationId.'/review';
        $token = $this->reviewToken($client, $url);
        $notes = str_repeat('é', 10000);
        $entityManager = $this->entityManager($client);
        $auditCount = $entityManager->getRepository(AuditLog::class)->count(['action' => 'guild_application.review']);

        $client->request('POST', $url, [
            '_token' => $token,
            'internal_notes' => $notes,
        ]);

        self::assertResponseRedirects($url);

        $entityManager->clear();
        $stored = $entityManager->find(GuildApplication::class, $applicationId);
        self::assertInstanceOf(GuildApplication::class, $stored);
        self::assertSame(GuildApplication::STATUS_REVIEWING, $stored->getStatus());
        self::assertSame($reviewerId, $stored->getAssignedTo()?->getId());
        self::assertSame($notes, $stored->getInternalNotes());
        self::assertSame(
            $auditCount + 1,
            $entityManager->getRepository(AuditLog::class)->count(['action' => 'guild_application.review']),
        );
    }

    private function reviewer(KernelBrowser $client): User
    {
        $reviewer = (new User())
            ->setEmail('application-review-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Application review boundary')
            ->setPermissions([CmsPermission::GAMING])
            ->verifyEmail();
        $this->entityManager($client)->persist($reviewer);
        $this->entityManager($client)->flush();

        return $reviewer;
    }

    private function application(KernelBrowser $client, ?User $reviewer = null): GuildApplication
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())->setName('Game '.$suffix)->setSlug('game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild '.$suffix)
            ->setSlug('guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Guild application note boundary test');
        $application = (new GuildApplication())
            ->setGuild($guild)
            ->setApplicantName('Boundary applicant')
            ->setEmail('applicant-'.$suffix.'@example.test')
            ->setCharacterName('Boundary character')
            ->setMessage('This application has enough text for the existing minimum.');

        if ($reviewer !== null) {
            $application->assignTo($reviewer)->setInternalNotes('Existing review note');
        }

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($application);
        $entityManager->flush();

        return $application;
    }

    private function reviewToken(KernelBrowser $client, string $url): string
    {
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form.content-form input[name="_token"]')->attr('value');
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
