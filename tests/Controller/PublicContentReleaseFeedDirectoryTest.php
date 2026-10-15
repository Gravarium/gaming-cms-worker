<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentReleaseFeedDirectoryTest extends WebTestCase
{
    public function testDirectoryLinksToBothPublicFeedFormats(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/releases/feeds');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertSame(1, $crawler->filter('a[href="/releases.atom"]')->count());
        self::assertSame(1, $crawler->filter('a[href="/releases.csv"]')->count());
        self::assertSame('Atom 1.0 abonnieren', trim($crawler->filter('a[href="/releases.atom"]')->text()));
        self::assertSame('CSV herunterladen', trim($crawler->filter('a[href="/releases.csv"]')->text()));
    }

    public function testDisabledContentModuleHidesFeedDirectory(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $existingState = $em->find(CmsModuleState::class, 'content');
        $previousEnabled = $existingState?->isEnabled();
        $state = $existingState ?? (new CmsModuleState())->setModuleKey('content')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        try {
            $client->request('GET', '/releases/feeds');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $currentState = $em->find(CmsModuleState::class, 'content');
            if ($previousEnabled === null) {
                if ($currentState instanceof CmsModuleState) {
                    $em->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                $currentState->setEnabled($previousEnabled);
            }
            $em->flush();
        }
    }
}
