<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCompetitionMatchScheduleTest extends WebTestCase
{
    public function testManagerGetsBoundedCompetitionScopedBoardAndWorkingCsrfForm(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        [$competition, $manager, $a, $b] = $this->fixture($em);
        $visible = $this->match($em, $competition, $a, $b, 1);
        $foreign = $this->fixture($em, 'Foreign schedule');
        $foreignMatch = $this->match($em, $foreign[0], $foreign[2], $foreign[3], 1);
        for ($number = 2; $number <= 55; ++$number) {
            $this->match($em, $competition, $a, $b, $number);
        }
        $em->flush();
        $client->loginUser($manager);
        $path = $this->path($competition);
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $competition->getName());
        self::assertSelectorCount(50, 'main article');
        self::assertStringNotContainsString($foreign[0]->getName(), (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('form[action="/admin/gaming/competitions/'.$foreign[0]->getId().'/match/'.$foreignMatch->getId().'/schedule"]');
        self::assertSelectorExists('form[action="/admin/gaming/competitions/'.$competition->getId().'/match/'.$visible->getId().'/schedule"] input[name="_token"]');
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $form = $client->getCrawler()->filter('form[action="/admin/gaming/competitions/'.$competition->getId().'/match/'.$visible->getId().'/schedule"]')->form();
        $form['scheduled_at'] = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:iP');
        $client->submit($form);
        self::assertResponseRedirects();
        $em->refresh($visible);
        self::assertNotNull($visible->getScheduledAt());
    }

    public function testPermissionModuleAndGetOnlyGate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em($client);
        [$competition, $manager] = $this->fixture($em);
        $unprivileged = $this->user($em, 'Schedule viewer', []);
        $em->flush();
        $path = $this->path($competition);

        $client->loginUser($unprivileged);
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

    /** @return array{Competition, User, CompetitionParticipant, CompetitionParticipant} */
    private function fixture(EntityManagerInterface $em, string $label = 'Schedule'): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName($label.' game')->setSlug('schedule-game-'.$suffix);
        $competition = (new Competition())->setGame($game)->setName($label.' cup '.$suffix)->setSlug('schedule-cup-'.$suffix)->open()->start();
        $manager = $this->user($em, 'Schedule manager', ['CMS_GAMING_MANAGE']);
        $a = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($manager)->setName($label.' Alpha')->checkIn();
        $b = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($manager)->setName($label.' Bravo')->checkIn();
        foreach ([$game, $competition, $a, $b] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$competition, $manager, $a, $b];
    }

    private function match(EntityManagerInterface $em, Competition $competition, CompetitionParticipant $a, CompetitionParticipant $b, int $sequence): CompetitionMatch
    {
        $match = (new CompetitionMatch())->setCompetition($competition)->setSequence($sequence)->setParticipants($a, $b)->markReady();
        $em->persist($match);

        return $match;
    }

    /** @param list<string> $permissions */
    private function user(EntityManagerInterface $em, string $name, array $permissions): User
    {
        $user = (new User())->setEmail('schedule-'.bin2hex(random_bytes(5)).'@example.test')->setDisplayName($name)->setPermissions($permissions)->verifyEmail();
        $em->persist($user);

        return $user;
    }

    private function path(Competition $competition): string
    {
        return '/admin/gaming/competitions/'.$competition->getId().'/schedule';
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
