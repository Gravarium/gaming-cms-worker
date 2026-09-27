<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Game;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCompetitionBracketWidgetTest extends WebTestCase
{
    public function testWidgetIsDiscoveredBoundedAndHiddenWhenGamingIsDisabled(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $originalLayout = $em->find(PageLayout::class, 'home');
        $hadOriginalLayout = $originalLayout instanceof PageLayout;
        $originalDocument = $originalLayout?->getDocument();
        /** @var array<string, bool|null> $originalModuleStates */
        $originalModuleStates = [];
        foreach (['core', 'content', 'gaming'] as $key) {
            $state = $em->find(CmsModuleState::class, $key);
            $originalModuleStates[$key] = $state?->isEnabled();
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey($key);
            }
            $state->setEnabled(true);
            $em->persist($state);
        }

        try {
            $game = $this->createGame($em, true);
            $start = new \DateTimeImmutable('2200-01-01 00:00:00 UTC');
            $public = [];
            for ($index = 0; $index < 9; ++$index) {
                $competition = $this->createCompetition($em, $game, 'Public Cup '.$index, $start->modify('+'.$index.' hours'));
                if ($index === 8) {
                    $competition->complete();
                }
                $public[] = $competition;
                $this->addMatch($em, $competition);
            }

            $private = $this->createCompetition($em, $game, 'Private Widget Cup', $start->modify('+20 hours'), false);
            $this->addMatch($em, $private);

            $draft = (new Competition())
                ->setGame($game)
                ->setName('Draft Widget Cup')
                ->setSlug($this->slug())
                ->setStartsAt($start->modify('+21 hours'));
            $em->persist($draft);
            $this->addMatch($em, $draft);

            $disabledGame = $this->createGame($em, true);
            $disabled = $this->createCompetition($em, $disabledGame, 'Disabled Game Cup', $start->modify('+22 hours'));
            $disabledGame->setEnabled(false);
            $this->addMatch($em, $disabled);

            $this->createCompetition($em, $game, 'No Matches Cup', $start->modify('+23 hours'));
            $em->flush();

            $newestId = $public[8]->getId();
            self::assertNotNull($newestId);

            $admin = (new User())
                ->setEmail('bracket-admin-'.bin2hex(random_bytes(5)).'@example.test')
                ->setDisplayName('Bracket editor')
                ->setPermissions([CmsPermission::ACCESS, CmsPermission::SETTINGS])
                ->verifyEmail();
            $em->persist($admin);

            $validator = $client->getContainer()->get(LayoutValidator::class);
            $document = $validator->defaults('nebula')->toArray();
            $document['widgets'] = [[
                'id' => 'public-brackets-fixture',
                'type' => 'gaming.competition-brackets',
                'region' => 'main',
                'enabled' => true,
                'config' => [],
            ]];
            $document = $validator->validate($document)->toArray();

            $layout = $em->find(PageLayout::class, 'home') ?? new PageLayout('home');
            $layout->replace($document);
            $em->persist($layout);
            $em->flush();

            $client->loginUser($admin);
            $paletteCrawler = $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            $editor = json_decode(
                (string) $paletteCrawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertContains('gaming.competition-brackets', array_column($editor['widgets'], 'key'));

            $crawler = $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSame(8, $crawler->filter('.public-competition-brackets a')->count());
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Public Cup 8', $html);
            self::assertStringContainsString('Status: Abgeschlossen', $html);
            self::assertStringContainsString('Status: Läuft', $html);
            self::assertStringNotContainsString('Public Cup 0', $html);
            self::assertStringNotContainsString('Private Widget Cup', $html);
            self::assertStringNotContainsString('Draft Widget Cup', $html);
            self::assertStringNotContainsString('Disabled Game Cup', $html);
            self::assertStringNotContainsString('No Matches Cup', $html);
            self::assertSelectorExists('a[href="/competitions/'.$newestId.'/bracket"]');

            $gamingState = $em->find(CmsModuleState::class, 'gaming');
            self::assertInstanceOf(CmsModuleState::class, $gamingState);
            $gamingState->setEnabled(false);
            $em->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.public-competition-brackets');

            $editorCrawler = $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            $disabledEditor = json_decode(
                (string) $editorCrawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertNotContains('gaming.competition-brackets', array_column($disabledEditor['widgets'], 'key'));
            self::assertSame('gaming.competition-brackets', $disabledEditor['document']['widgets'][0]['type']);

            $client->request('GET', '/competitions/'.$newestId.'/bracket');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $layout = $em->find(PageLayout::class, 'home');
            if ($hadOriginalLayout && is_array($originalDocument)) {
                if (!$layout instanceof PageLayout) {
                    $layout = new PageLayout('home');
                    $em->persist($layout);
                }
                $layout->replace($originalDocument);
            } elseif ($layout instanceof PageLayout) {
                $em->remove($layout);
            }

            foreach ($originalModuleStates as $key => $enabled) {
                $state = $em->find(CmsModuleState::class, $key);
                if ($enabled === null) {
                    if ($state instanceof CmsModuleState) {
                        $em->remove($state);
                    }
                } elseif ($state instanceof CmsModuleState) {
                    $state->setEnabled($enabled);
                }
            }
            $em->flush();
        }
    }

    private function createGame(EntityManagerInterface $em, bool $enabled): Game
    {
        $game = (new Game())->setName('Widget game')->setSlug($this->slug())->setEnabled($enabled);
        $em->persist($game);

        return $game;
    }

    private function createCompetition(EntityManagerInterface $em, Game $game, string $name, \DateTimeImmutable $startsAt, bool $public = true): Competition
    {
        $competition = (new Competition())
            ->setGame($game)
            ->setName($name)
            ->setSlug($this->slug())
            ->setStartsAt($startsAt);

        if (!$public) {
            $competition->setVisibility(Competition::VISIBILITY_PRIVATE);
        }

        $competition->open()->start();
        $em->persist($competition);

        return $competition;
    }

    private function addMatch(EntityManagerInterface $em, Competition $competition): void
    {
        $em->persist((new CompetitionMatch())->setCompetition($competition));
    }

    private function slug(): string
    {
        return 'public-bracket-'.bin2hex(random_bytes(8));
    }
}
