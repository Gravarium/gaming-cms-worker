<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionDispute;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCompetitionDisputeQueueTest extends WebTestCase
{
    public function testManagerSeesOpenCaseAndExistingDecisionFormWithoutCaching(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$manager, $dispute, $competition] = $this->fixture($client);
        $client->loginUser($manager);

        $client->request('GET', '/admin/gaming/competitions/disputes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Offene Competition-Streitfälle');
        self::assertStringContainsString('Unklarer Spielstand', (string) $client->getResponse()->getContent());
        self::assertSelectorExists(sprintf('form[action="/admin/gaming/competitions/%d/dispute/%d/decide"]', $competition->getId(), $dispute->getId()));
        self::assertSelectorExists('form input[name="_token"]');
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testQueueRequiresGamingPermission(): void
    {
        $client = static::createClient();
        $user = $this->user([], 'visitor');
        $this->em($client)->persist($user);
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/gaming/competitions/disputes');

        self::assertResponseStatusCodeSame(403);
    }

    public function testQueueIsHiddenWithGamingModule(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $manager = $this->user([CmsPermission::GAMING], 'manager');
        $this->em($client)->persist($manager);
        $this->em($client)->flush();
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'gaming');
        $created = $state === null;
        $originalEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())->setModuleKey('gaming');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();
        $client->loginUser($manager);

        try {
            $client->request('GET', '/admin/gaming/competitions/disputes');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($created) {
                $em->remove($state);
            } else {
                $state->setEnabled($originalEnabled);
            }
            $em->flush();
        }
    }

    /** @return array{User, CompetitionDispute, Competition} */
    private function fixture(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(5));
        $manager = $this->user([CmsPermission::GAMING], 'manager');
        $other = $this->user([], 'captain');
        $game = (new Game())->setName('Arena '.$suffix)->setSlug('arena-'.$suffix);
        $competition = (new Competition())->setGame($game)->setCreatedBy($manager)->setName('Cup '.$suffix)->setSlug('cup-'.$suffix);
        $competition->open()->start();
        $a = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($manager)->setName('Alpha');
        $b = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($other)->setName('Beta');
        $a->checkIn();
        $b->checkIn();
        $match = (new CompetitionMatch())->setCompetition($competition)->setParticipants($a, $b)->markReady();
        $match->submitResult($a, 2, 1, $manager)->markDisputed();
        $dispute = (new CompetitionDispute())->setMatch($match)->setOpenedBy($other)->setReason('Unklarer Spielstand');
        $em = $this->em($client);
        foreach ([$manager, $other, $game, $competition, $a, $b, $match, $dispute] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$manager, $dispute, $competition];
    }

    /** @param list<string> $permissions */
    private function user(array $permissions, string $name): User
    {
        return (new User())
            ->setEmail($name.'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName($name)
            ->setPermissions($permissions)
            ->verifyEmail();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
