<?php

declare(strict_types=1);

namespace App\\Tests\\Controller;

use App\\Entity\\CmsModuleState;
use App\\Module\\CmsModuleManager;
use Doctrine\\ORM\\EntityManagerInterface;
use Symfony\\Bundle\\FrameworkBundle\\KernelBrowser;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;

final class PublicGameCatalogueGenreDirectoryNavigationTest extends WebTestCase
{
    public function testPublicSearchLinksToThePublicGenreDirectory(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $moduleSnapshot = $this->captureGamingModuleState($client);

        try {
            $this->setGamingModuleEnabled($client, true);
            $crawler = $client->request('GET', '/game-search');

            self::assertResponseIsSuccessful();
            $link = $crawler->filter('a.game-genre-directory-link');
            self::assertCount(1, $link);
            self::assertSame('/games/genres', $link->attr('href'));
            self::assertSame('Spiele nach Genre entdecken', trim($link->text()));

            $client->click($link->link());

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Spielgenres');
        } finally {
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{exists: bool, enabled: bool}
     */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for public game genre navigation.');
        }

        $state = $this->entityManager($client)->find(CmsModuleState::class, 'gaming');

        return ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setGamingModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())
                ->setModuleKey('gaming')
                ->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /**
     * @param array{exists: bool, enabled: bool} $snapshot
     */
    private function restoreGamingModuleState(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if (!$snapshot['exists']) {
            if ($state instanceof CmsModuleState) {
                $entityManager->remove($state);
                $entityManager->flush();
            }

            return;
        }

        if ($state instanceof CmsModuleState && $state->isEnabled() !== $snapshot['enabled']) {
            $state->setEnabled($snapshot['enabled']);
            $entityManager->flush();
        }
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
