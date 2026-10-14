<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGameReleaseFeedsPageTest extends WebTestCase
{
    public function testDirectoryLinksToEveryPublicReleaseFeed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $previous = $this->setGamingEnabled($entityManager, true);

        try {
            $client->request('GET', '/games/releases/feeds');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Spiel-Releases abonnieren');
            self::assertSelectorExists('a[href="/games/releases/feed.xml"]');
            self::assertSelectorExists('a[href="/games/releases/calendar.ics"]');
            self::assertSelectorExists('a[href="/games/releases/feed.json"]');
            self::assertSelectorExists('a[href="/games/releases"]');
        } finally {
            $this->restoreGamingState($entityManager, $previous);
        }
    }

    public function testDirectoryFailsClosedWhenGamingIsDisabledAndRejectsWrites(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $previous = $this->setGamingEnabled($entityManager, false);

        try {
            $client->request('GET', '/games/releases/feeds');
            self::assertResponseStatusCodeSame(404);

            $client->request('POST', '/games/releases/feeds');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->restoreGamingState($entityManager, $previous);
        }
    }

    private function setGamingEnabled(EntityManagerInterface $entityManager, bool $enabled): ?bool
    {
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        $previous = $state?->isEnabled();
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        }
        $state->setEnabled($enabled);
        $entityManager->persist($state);
        $entityManager->flush();

        return $previous;
    }

    private function restoreGamingState(EntityManagerInterface $entityManager, ?bool $previous): void
    {
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        if (!$state instanceof CmsModuleState) {
            return;
        }

        if ($previous === null) {
            $entityManager->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $entityManager->flush();
    }
}
