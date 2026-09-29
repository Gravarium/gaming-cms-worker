<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompetitionRostersWidgetTest extends WebTestCase
{
    private const KEY = 'gaming.competition-rosters';
    private const MODULES = ['content', 'gaming'];

    public function testPageBuilderWidgetShowsOnlyTheSignedInCaptainsPublicOpenTeamRoster(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];
        $layoutSnapshot = $this->setHomeLayout($entityManager);

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'widget-captain-'.$suffix);
            $member = $this->user($entityManager, 'widget-member-'.$suffix);
            $otherCaptain = $this->user($entityManager, 'widget-other-'.$suffix);
            array_push($fixtures, $captain, $member, $otherCaptain);
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;

            $visible = $this->competition($entityManager, $game, $suffix.'-visible', Competition::MODE_TEAM, 3);
            $foreign = $this->competition($entityManager, $game, $suffix.'-foreign', Competition::MODE_TEAM, 3);
            $private = $this->competition($entityManager, $game, $suffix.'-private', Competition::MODE_TEAM, 3, Competition::VISIBILITY_PRIVATE);
            $solo = $this->competition($entityManager, $game, $suffix.'-solo', Competition::MODE_SOLO, 1);
            $closed = $this->competition($entityManager, $game, $suffix.'-closed', Competition::MODE_TEAM, 3);
            $closed->archive();
            array_push($fixtures, $visible, $foreign, $private, $solo, $closed);
            $entityManager->flush();

            $ownEntry = $this->participant($entityManager, $visible, $captain, 'Own team '.$suffix, [$captain, $member]);
            $foreignEntry = $this->participant($entityManager, $foreign, $otherCaptain, 'Foreign team '.$suffix, [$otherCaptain]);
            $privateEntry = $this->participant($entityManager, $private, $captain, 'Private team '.$suffix, [$captain]);
            $soloEntry = $this->participant($entityManager, $solo, $captain, 'Solo entry '.$suffix, [$captain]);
            $closedEntry = $this->participant($entityManager, $closed, $captain, 'Closed team '.$suffix, [$captain]);
            array_push($fixtures, $ownEntry, $foreignEntry, $privateEntry, $soloEntry, $closedEntry);
            $entityManager->flush();

            $client->loginUser($captain);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Visible '.$suffix);
            self::assertSelectorTextContains('body', 'Own team '.$suffix);
            self::assertSelectorTextContains('body', $member->getDisplayName());
            self::assertSelectorTextNotContains('body', $member->getEmail());
            self::assertSelectorTextNotContains('body', 'Foreign team '.$suffix);
            self::assertSelectorTextNotContains('body', 'Private team '.$suffix);
            self::assertSelectorTextNotContains('body', 'Solo entry '.$suffix);
            self::assertSelectorTextNotContains('body', 'Closed team '.$suffix);
            self::assertSelectorExists('a[href="/account/competition-rosters"]');
            $this->assertPrivateResponse($client);

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('gaming', $definition->module);
            self::assertSame('widget/my_rosters.html.twig', $definition->template);
            self::assertTrue($registry->available(self::KEY));
            $availableKeys = array_map(static fn (WidgetDefinition $item): string => $item->key, $registry->availableDefinitions());
            self::assertContains(self::KEY, $availableKeys);
            self::assertTrue($client->getContainer()->get(CmsModuleManager::class)->isEnabled('gaming'));

            $client->request('GET', '/account/competition-rosters');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Own team '.$suffix);
            $this->assertPrivateResponse($client);
        } finally {
            try {
                $this->removeHomeLayout($this->entityManager($client), $layoutSnapshot);
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

    public function testGuestCannotSeeRosterDetailsAndDisabledGamingSuppressesTheWidgetAndDashboard(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];
        $layoutSnapshot = $this->setHomeLayout($entityManager);

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'guest-captain-'.$suffix);
            $member = $this->user($entityManager, 'guest-member-'.$suffix);
            array_push($fixtures, $captain, $member);
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $competition = $this->competition($entityManager, $game, $suffix, Competition::MODE_TEAM, 2);
            $fixtures[] = $competition;
            $entityManager->flush();
            $participant = $this->participant($entityManager, $competition, $captain, 'Captain-only team '.$suffix, [$captain, $member]);
            $fixtures[] = $participant;
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Melde dich an, um deinen Teamkader zu sehen.');
            self::assertSelectorTextNotContains('body', 'Captain-only team '.$suffix);
            self::assertSelectorTextNotContains('body', $member->getDisplayName());

            $this->setModuleEnabled($this->entityManager($client), 'gaming', false);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('body', 'Meine Competition-Teams');
            self::assertSelectorTextNotContains('body', 'Teamkader verwalten');

            $client->loginUser($captain);
            $client->request('GET', '/account/competition-rosters');
            self::assertResponseStatusCodeSame(404);

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available(self::KEY));
        } finally {
            try {
                $this->removeHomeLayout($this->entityManager($client), $layoutSnapshot);
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

    public function testDashboardAndWidgetResultsAreBounded(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];
        $layoutSnapshot = $this->setHomeLayout($entityManager);

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'bounded-captain-'.$suffix);
            $fixtures[] = $captain;
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $entityManager->flush();

            for ($index = 1; $index <= 22; ++$index) {
                $competition = $this->competition(
                    $entityManager,
                    $game,
                    sprintf('%s-%02d', $suffix, $index),
                    Competition::MODE_TEAM,
                    2,
                );
                $fixtures[] = $competition;
                $entityManager->flush();
                $participant = $this->participant(
                    $entityManager,
                    $competition,
                    $captain,
                    sprintf('Bounded team %02d %s', $index, $suffix),
                    [$captain],
                );
                $fixtures[] = $participant;
                $entityManager->flush();
            }

            $client->loginUser($captain);
            $crawler = $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertCount(6, $crawler->filter('section[aria-labelledby="my-competition-rosters-title"] > ul > li'));
            $this->assertPrivateResponse($client);

            $crawler = $client->request('GET', '/account/competition-rosters');
            self::assertResponseIsSuccessful();
            self::assertCount(20, $crawler->filter('article.portal-card'));
            self::assertSelectorTextNotContains('body', sprintf('Bounded team 21 %s', $suffix));
            self::assertSelectorTextNotContains('body', sprintf('Bounded team 22 %s', $suffix));
            $this->assertPrivateResponse($client);
        } finally {
            try {
                $this->removeHomeLayout($this->entityManager($client), $layoutSnapshot);
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

    /** @return array{existed: bool, document: array<string, mixed>} */
    private function setHomeLayout(EntityManagerInterface $entityManager): array
    {
        $layout = $entityManager->find(PageLayout::class, 'home');
        $snapshot = ['existed' => $layout instanceof PageLayout, 'document' => $layout?->getDocument() ?? []];
        if (!$layout instanceof PageLayout) {
            $layout = new PageLayout('home');
            $entityManager->persist($layout);
        }
        $layout->replace([
            'schema' => 1,
            'theme' => 'nebula',
            'options' => [],
            'widgets' => [[
                'id' => 'my-competition-rosters',
                'type' => self::KEY,
                'region' => 'main',
                'enabled' => true,
                'config' => [],
            ]],
        ]);
        $entityManager->flush();

        return $snapshot;
    }

    /** @param array{existed: bool, document: array<string, mixed>} $snapshot */
    private function removeHomeLayout(EntityManagerInterface $entityManager, array $snapshot): void
    {
        $layout = $entityManager->find(PageLayout::class, 'home');
        if (!$layout instanceof PageLayout) {
            return;
        }
        if (!$snapshot['existed']) {
            $entityManager->remove($layout);
        } else {
            $layout->replace($snapshot['document']);
        }
        $entityManager->flush();
    }

    private function user(EntityManagerInterface $entityManager, string $suffix): User
    {
        $user = (new User())
            ->setEmail('roster-widget-'.$suffix.'@example.test')
            ->setDisplayName('Widget '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $entityManager->persist($user);

        return $user;
    }

    private function game(EntityManagerInterface $entityManager, string $suffix): Game
    {
        $game = (new Game())->setName('Widget game '.$suffix)->setSlug('widget-game-'.$suffix);
        $entityManager->persist($game);

        return $game;
    }

    private function competition(
        EntityManagerInterface $entityManager,
        Game $game,
        string $slug,
        string $mode,
        int $teamSize,
        string $visibility = Competition::VISIBILITY_PUBLIC,
    ): Competition {
        $competition = (new Competition())
            ->setGame($game)
            ->setName('Visible '.$slug)
            ->setSlug('competition-'.$slug)
            ->setMode($mode)
            ->setTeamSize($teamSize)
            ->setVisibility($visibility);
        $competition->open();
        $entityManager->persist($competition);

        return $competition;
    }

    /** @param list<User> $members */
    private function participant(EntityManagerInterface $entityManager, Competition $competition, User $captain, string $name, array $members): CompetitionParticipant
    {
        $participant = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($captain)
            ->setName($name);
        $ids = [];
        foreach ($members as $member) {
            if ($member->getId() !== null) {
                $ids[] = $member->getId();
            }
        }
        $participant->setRosterUserIds($ids);
        $entityManager->persist($participant);

        return $participant;
    }

    /** @param list<object> $fixtures */
    private function removeFixtures(EntityManagerInterface $entityManager, array $fixtures): void
    {
        foreach (array_reverse($fixtures) as $fixture) {
            $identifiers = $entityManager->getClassMetadata($fixture::class)->getIdentifierValues($fixture);
            if (count($identifiers) !== 1) {
                continue;
            }
            $identifier = array_values($identifiers)[0] ?? null;
            if ($identifier === null) {
                continue;
            }
            $managed = $entityManager->find($fixture::class, $identifier);
            if ($managed !== null) {
                $entityManager->remove($managed);
            }
        }
        if ($fixtures !== []) {
            $entityManager->flush();
        }
        $entityManager->clear();
    }

    /** @return array<string, array{exists: bool, enabled: bool}> */
    private function captureModuleStates(KernelBrowser $client): array
    {
        $entityManager = $this->entityManager($client);
        $snapshot = [];
        foreach (self::MODULES as $key) {
            $state = $entityManager->find(CmsModuleState::class, $key);
            $snapshot[$key] = ['exists' => $state instanceof CmsModuleState, 'enabled' => $state?->isEnabled() ?? true];
        }

        return $snapshot;
    }

    private function setModulesEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        foreach (self::MODULES as $key) {
            $this->setModuleEnabled($entityManager, $key, $enabled);
        }
    }

    private function setModuleEnabled(EntityManagerInterface $entityManager, string $key, bool $enabled): void
    {
        $state = $entityManager->find(CmsModuleState::class, $key);
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey($key)->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /** @param array<string, array{exists: bool, enabled: bool}> $snapshot */
    private function restoreModuleStates(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        foreach ($snapshot as $key => $state) {
            $current = $entityManager->find(CmsModuleState::class, $key);
            if (!$state['exists']) {
                if ($current instanceof CmsModuleState) {
                    $entityManager->remove($current);
                }
                continue;
            }
            if ($current instanceof CmsModuleState) {
                $current->setEnabled($state['enabled']);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function assertPrivateResponse(KernelBrowser $client): void
    {
        $directives = array_map('trim', explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))));
        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
        self::assertContains('max-age=0', $directives);
    }
}
