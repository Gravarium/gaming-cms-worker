<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionDispute;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCompetitionDisputeBoardTest extends WebTestCase
{
    public function testManagerSeesOnlyOwnOpenDisputesAndCanDecideWithExistingCsrfAction(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        [$competition, $manager, $a, $b, $captainA, $captainB] = $this->fixture($em);
        $dispute = $this->dispute($em, $competition, $a, $b, $captainA, $captainB, 1, 'Visible dispute reason');
        [$foreign, , $foreignA, $foreignB, $foreignCaptainA, $foreignCaptainB] = $this->fixture($em, 'Foreign');
        $this->dispute($em, $foreign, $foreignA, $foreignB, $foreignCaptainA, $foreignCaptainB, 1, 'Foreign secret reason');
        $em->flush();
        $client->loginUser($manager);
        $client->request('GET', $this->path($competition));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $competition->getName());
        self::assertSelectorTextContains('article', 'Visible dispute reason');
        self::assertSelectorCount(1, 'main article');
        self::assertStringNotContainsString('Foreign secret reason', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($captainB->getEmail(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $form = $client->getCrawler()->filter('main article form')->first()->form();
        self::assertSame('rejected', $form->getValues()['status']);
        $form['decision'] = 'The original score is confirmed.';
        $client->submit($form);
        self::assertResponseRedirects();
        $stored = $this->em($client)->find(CompetitionDispute::class, $dispute->getId());
        self::assertInstanceOf(CompetitionDispute::class, $stored);
        self::assertFalse($stored->isOpen());
        self::assertSame(CompetitionMatch::STATUS_CONFIRMED, $stored->getMatch()?->getStatus());

        $client->request('GET', $this->path($competition));
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(0, 'main article');
    }

    public function testNewestFiftyOnlyAndInvalidCsrfDoesNotDecide(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        [$competition, $manager, $a, $b, $captainA, $captainB] = $this->fixture($em);
        $oldest = $this->dispute($em, $competition, $a, $b, $captainA, $captainB, 1, 'Oldest private dispute');
        for ($sequence = 2; $sequence <= 52; ++$sequence) {
            $this->dispute($em, $competition, $a, $b, $captainA, $captainB, $sequence, 'Dispute '.$sequence);
        }
        $em->flush();
        $client->loginUser($manager);
        $client->request('GET', $this->path($competition));

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(50, 'main article');
        self::assertStringNotContainsString('Oldest private dispute', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('main article:first-of-type', 'Dispute 52');

        $client->request('POST', '/admin/gaming/competitions/'.$competition->getId().'/dispute/'.$oldest->getId().'/decide', [
            '_token' => 'forged', 'status' => 'rejected', 'decision' => 'unauthorized',
        ]);
        self::assertResponseStatusCodeSame(403);
        $stored = $this->em($client)->find(CompetitionDispute::class, $oldest->getId());
        self::assertInstanceOf(CompetitionDispute::class, $stored);
        self::assertTrue($stored->isOpen());
    }

    public function testPermissionGamingModuleAndGetOnlyRoute(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em($client);
        [$competition, $manager] = $this->fixture($em);
        $viewer = $this->user($em, 'Viewer', []);
        $em->flush();
        $path = $this->path($competition);
        $client->loginUser($viewer);
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        $modules = $client->getContainer()->get(CmsModuleManager::class);
        $enabled = $modules->isEnabled('gaming');
        $modules->setEnabled('gaming', false);
        $client->restart();
        $client->loginUser($manager);
        try {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($enabled) {
                $modules->setEnabled('gaming', true);
            }
        }

        $client->request('POST', $path);
        self::assertResponseStatusCodeSame(405);
    }

    /** @return array{Competition, User, CompetitionParticipant, CompetitionParticipant, User, User} */
    private function fixture(EntityManagerInterface $em, string $label = 'Review'): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName($label.' game')->setSlug('dispute-game-'.$suffix);
        $competition = (new Competition())->setGame($game)->setName($label.' cup '.$suffix)->setSlug('dispute-cup-'.$suffix)->open()->start();
        $manager = $this->user($em, $label.' manager', ['CMS_GAMING_MANAGE']);
        $captainA = $this->user($em, $label.' captain A', []);
        $captainB = $this->user($em, $label.' captain B', []);
        $a = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($captainA)->setName($label.' Alpha')->checkIn();
        $b = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($captainB)->setName($label.' Bravo')->checkIn();
        foreach ([$game, $competition, $a, $b] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$competition, $manager, $a, $b, $captainA, $captainB];
    }

    private function dispute(EntityManagerInterface $em, Competition $competition, CompetitionParticipant $a, CompetitionParticipant $b, User $captainA, User $captainB, int $sequence, string $reason): CompetitionDispute
    {
        $match = (new CompetitionMatch())->setCompetition($competition)->setSequence($sequence)->setParticipants($a, $b)->markReady();
        $match->submitResult($a, 2, 1, $captainA)->markDisputed();
        $dispute = (new CompetitionDispute())->setMatch($match)->setOpenedBy($captainB)->setReason($reason);
        $em->persist($match);
        $em->persist($dispute);

        return $dispute;
    }

    /** @param list<string> $permissions */
    private function user(EntityManagerInterface $em, string $name, array $permissions): User
    {
        $user = (new User())->setEmail('dispute-'.bin2hex(random_bytes(5)).'@example.test')->setDisplayName($name)->setPermissions($permissions)->verifyEmail();
        $em->persist($user);

        return $user;
    }

    private function path(Competition $competition): string
    {
        return '/admin/gaming/competitions/'.$competition->getId().'/disputes';
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
