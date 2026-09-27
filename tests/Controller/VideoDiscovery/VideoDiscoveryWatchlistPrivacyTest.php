<?php

declare(strict_types=1);

namespace App\Tests\Controller\VideoDiscovery;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\VideoDiscovery\VideoWatchlist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoDiscoveryWatchlistPrivacyTest extends WebTestCase
{
    public function testPrivateWatchlistIsHiddenFromAnonymousAndForeignViewers(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $this->enableVideoModule($client);

        $owner = $this->user($client, 'owner');
        $other = $this->user($client, 'other');
        $suffix = bin2hex(random_bytes(5));
        $privateName = 'Private watchlist '.$suffix;
        $publicName = 'Public watchlist '.$suffix;
        $private = new VideoWatchlist($owner, $privateName);
        $public = (new VideoWatchlist($owner, $publicName))->setPublic(true);
        $entityManager->persist($private);
        $entityManager->persist($public);
        $entityManager->flush();

        $privateId = $private->getId();
        $publicId = $public->getId();
        self::assertNotNull($privateId);
        self::assertNotNull($publicId);

        $crawler = $client->request('GET', '/video-discovery/watchlists/'.$privateId);
        self::assertResponseStatusCodeSame(404);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString($privateName, $content);

        $client->loginUser($other);
        $client->request('GET', '/video-discovery/watchlists/'.$privateId);
        self::assertResponseStatusCodeSame(404);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString($privateName, $content);

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/video-discovery/watchlists/'.$privateId);
        self::assertResponseIsSuccessful();
        self::assertSame($privateName, $crawler->filter('h1')->text());

        $client->loginUser($other);
        $crawler = $client->request('GET', '/video-discovery/watchlists/'.$publicId);
        self::assertResponseIsSuccessful();
        self::assertSame($publicName, $crawler->filter('h1')->text());
    }

    private function user(KernelBrowser $client, string $suffix): User
    {
        $user = (new User())
            ->setEmail('video-watchlist-'.$suffix.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Video watchlist '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $client->getContainer()->get(EntityManagerInterface::class)->persist($user);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private function enableVideoModule(KernelBrowser $client): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $state = $entityManager->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled(true);
        $entityManager->flush();
    }
}
