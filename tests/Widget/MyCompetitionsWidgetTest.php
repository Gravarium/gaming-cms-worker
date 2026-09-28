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

final class MyCompetitionsWidgetTest extends WebTestCase
{
    private const KEY = 'gaming.my-competition-entries';
    private const MODULES = ['content', 'gaming'];

    public function testPageBuilderShowsOnlyTheSignedInCaptainsEntriesAndMarksTheResponseNoStore(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];
        $layoutSnapshot = $this->setHomeLayout($entityManager);

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'captain-'.$suffix);
            $fixtures[] = $captain;
            $otherCaptain = $this->user($entityManager, 'other-'.$suffix);
            $fixtures[] = $otherCaptain;
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $visible = $this->competition($entityManager, $game, 'Visible competition '.$suffix, $suffix.'-visible');
            $fixtures[] = $visible;
            $ownEntry = $this->participant($entityManager, $visible, $captain, 'Own team '.$suffix);
            $fixtures[] = $ownEntry;
            $foreign = $this->competition($entityManager, $game, 'Foreign competition '.$suffix, $suffix.'-foreign');
            $fixtures[] = $foreign;
            $foreignEntry = $this->participant($entityManager, $foreign, $otherCaptain, 'Foreign team '.$suffix);
            $fixtures[] = $foreignEntry;
            $private = $this->competition($entityManager, $game, 'Private competition '.$suffix, $suffix.'-private')
                ->setVisibility(Competition::VISIBILITY_PRIVATE)
                ->setCreatedBy($otherCaptain);
            $entityManager->flush();
            $fixtures[] = $private;
            $privateEntry = $this->participant($entityManager, $private, $captain, 'Private entry '.$suffix);
            $fixtures[] = $privateEntry;
            $entityManager->flush();

            $client->loginUser($captain);
            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Visible competition '.$suffix);
            self::assertSelectorTextContains('body', 'Own team '.$suffix);
            self::assertSelectorTextContains('body', 'Aktive Anmeldungen: 1');
            self::assertSelectorTextNotContains('body', 'Foreign competition '.$suffix);
            self::assertSelectorTextNotContains('body', 'Foreign team '.$suffix);
            self::assertSelectorTextNotContains('body', 'Private competition '.$suffix);
            self::assertSelectorTextNotContains('body', 'Private entry '.$suffix);
            self::assertSelectorExists('a[href="/account/competitions"]');
            $this->assertPersonalizedResponseIsNotCacheable($client);

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('gaming', $definition->module);
            self::assertTrue($registry->available(self::KEY));
            self::assertTrue($client->getContainer()->get(CmsModuleManager::class)->isEnabled('gaming'));
        } finally {
            try {
                $cleanupEntityManager = $this->entityManager($client);
                $this->removeHomeLayout($cleanupEntityManager, $layoutSnapshot);
                $this->removeFixtures($cleanupEntityManager, $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

    public function testGuestWidgetShowsOnlyTheSignInPromptAndDisabledGamingSuppressesIt(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];
        $layoutSnapshot = $this->setHomeLayout($entityManager);

        try {
            $suffix = bin2hex(random_bytes(5));
            $owner = $this->user($entityManager, 'owner-'.$suffix);
            $fixtures[] = $owner;
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $competition = $this->competition($entityManager, $game, 'Captain-only '.$suffix, $suffix.'-captain-only');
            $fixtures[] = $competition;
            $participant = $this->participant($entityManager, $competition, $owner, 'Private entry '.$suffix);
            $fixtures[] = $participant;
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Melde dich an, um deine Competition-Anmeldungen zu sehen.');
            self::assertSelectorTextNotContains('body', 'Captain-only '.$suffix);
            self::assertSelectorTextNotContains('body', 'Private entry '.$suffix);
            $this->setModuleEnabled($this->entityManager($client), 'gaming', false);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('body', 'Meine Competitions');
            self::assertSelectorTextNotContains('body', 'Melde dich an, um deine Competition-Anmeldungen zu sehen.');

            $client->loginUser($owner);
            $client->request('GET', '/account/competitions');
            self::assertResponseStatusCodeSame(404);
        } finally {
            try {
                $cleanupEntityManager = $this->entityManager($client);
                $this->removeHomeLayout($cleanupEntityManager, $layoutSnapshot);
                $this->removeFixtures($cleanupEntityManager, $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

    public function testWidgetAndDashboardListsStayWithinTheirConfiguredBounds(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];
        $layoutSnapshot = $this->setHomeLayout($entityManager);

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'bounded-'.$suffix);
            $fixtures[] = $captain;
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $competition = $this->competition($entityManager, $game, 'Bounded '.$suffix, $suffix.'-bounded');
            $fixtures[] = $competition;

            for ($index = 1; $index <= 26; ++$index) {
                $fixtures[] = $this->participant(
                    $entityManager,
                    $competition,
                    $captain,
                    sprintf('Team %02d %s', $index, $suffix),
                );
            }
            $entityManager->flush();

            $client->loginUser($captain);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Aktive Anmeldungen: 6');
            self::assertSelectorTextContains('body', 'Team 26 '.$suffix);
            self::assertSelectorTextNotContains('body', 'Team 01 '.$suffix);
            $this->assertPersonalizedResponseIsNotCacheable($client);

            $client->request('GET', '/account/competitions');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Team 26 '.$suffix);
            self::assertSelectorTextContains('body', 'Team 03 '.$suffix);
            self::assertSelectorTextNotContains('body', 'Team 02 '.$suffix);
            self::assertSelectorTextNotContains('body', 'Team 01 '.$suffix);
            $this->assertPersonalizedResponseIsNotCacheable($client);
        } finally {
            try {
                $cleanupEntityManager = $this->entityManager($client);
                $this->removeHomeLayout($cleanupEntityManager, $layoutSnapshot);
                $this->removeFixtures($cleanupEntityManager, $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

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
                'id' => 'my-competition-entries',
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
        if ($snapshot['existed']) {
            $layout->replace($snapshot['document']);
        } else {
            $entityManager->remove($layout);
        }
        $entityManager->flush();
    }

    private function user(EntityManagerInterface $entityManager, string $suffix): User
    {
        $user = (new User())
            ->setEmail('competition-widget-'.$suffix.'@example.test')
            ->setDisplayName('Competition widget '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $entityManager->persist($user);

        return $user;
    }

    private function game(EntityManagerInterface $entityManager, string $suffix): Game
    {
        $game = (new Game())->setName('Game '.$suffix)->setSlug('game-'.$suffix);
        $entityManager->persist($game);

        return $game;
    }

    private function competition(EntityManagerInterface $entityManager, Game $game, string $name, string $slug): Competition
    {
        $competition = (new Competition())
            ->setGame($game)
            ->setName($name)
            ->setSlug('competition-'.$slug);
        $competition->open();
        $entityManager->persist($competition);

        return $competition;
    }

    private function participant(EntityManagerInterface $entityManager, Competition $competition, User $captain, string $name): CompetitionParticipant
    {
        $participant = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($captain)
            ->setName($name);
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

    private function assertPersonalizedResponseIsNotCacheable(KernelBrowser $client): void
    {
        $directives = array_map('trim', explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))));
        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
        self::assertContains('max-age=0', $directives);
    }
}
