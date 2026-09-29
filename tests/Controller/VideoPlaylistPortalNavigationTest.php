<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoPlaylistPortalNavigationTest extends WebTestCase
{
    public function testPlaylistLinkOpensDirectoryAndFollowsVideoModuleAvailability(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $state = $entityManager->find(CmsModuleState::class, 'video');
        $createdState = !($state instanceof CmsModuleState);
        $originalEnabled = $state instanceof CmsModuleState ? $state->isEnabled() : null;

        try {
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey('video');
                $entityManager->persist($state);
            }
            $state->setEnabled(true);
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            $playlistLink = $client->getCrawler()->filter('nav[aria-label="Hauptnavigation"] a[href="/video-playlists"]');
            self::assertSame(1, $playlistLink->count(), 'The enabled video module should expose the playlist directory in the main navigation.');

            $client->click($playlistLink->link());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Video-Playlists');

            $stateManager = $client->getContainer()->get(EntityManagerInterface::class);
            $enabledState = $stateManager->find(CmsModuleState::class, 'video');
            self::assertInstanceOf(CmsModuleState::class, $enabledState);
            $enabledState->setEnabled(false);
            $stateManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('nav[aria-label="Hauptnavigation"] a[href="/video-playlists"]');

            $client->request('GET', '/video-playlists');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            $cleanupState = $cleanupManager->find(CmsModuleState::class, 'video');
            if ($createdState) {
                if ($cleanupState instanceof CmsModuleState) {
                    $cleanupManager->remove($cleanupState);
                }
            } elseif ($cleanupState instanceof CmsModuleState) {
                $cleanupState->setEnabled((bool) $originalEnabled);
            }
            $cleanupManager->flush();
        }
    }
}
