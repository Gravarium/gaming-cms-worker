<?php

declare(strict_types=1);

namespace App\Tests\Controller\VideoDiscovery;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\VideoDiscovery\VideoWatchlist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoDiscoveryWatchlistPrivacyTest extends WebTestCase
{
    public function testPrivateWatchlistIsHiddenFromAnonymousViewer(): void
    {
        $client = static::createClient();
        $this->enableVideoModule($client);
        $watchlists = $this->createWatchlists($client);

        $client->request('GET', '/video-discovery/watchlists/'.$watchlists['privateId']);

        self::assertResponseStatusCodeSame(404);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString($watchlists['privateName'], $content);
    }

    public function testForeignViewerCannotViewPrivateWatchlistButCanViewPublicWatchlist(): void
    {
        $client = static::createClient();
        $this->enableVideoModule($client);
        $watchlists = $this->createWatchlists($client);
        $client->loginUser($watchlists['other']);

        $client->request('GET', '/video-discovery/watchlists/'.$watchlists['privateId']);

        self::assertResponseStatusCodeSame(404);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString($watchlists['privateName'], $content);

        $crawler = $client->request('GET', '/video-discovery/watchlists/'.$watchlists['publicId']);

        self::assertResponseIsSuccessful();
        self::assertSame($watchlists['publicName'], $crawler->filter('h1')->text());
    }

    public function testOwnerCanViewPrivateWatchlist(): void
    {
        $client = static::createClient();
        $this->enableVideoModule($client);
        $watchlists = $this->createWatchlists($client);
        $client->loginUser($watchlists['owner']);

        $crawler = $client->request('GET', '/video-discovery/watchlists/'.$watchlists['privateId']);

        self::assertResponseIsSuccessful();
        self::assertSame($watchlists['privateName'], $crawler->filter('h1')->text());
    }

    /**
     * @return array{owner: User, other: User, privateId: int, publicId: int, privateName: string, publicName: string}
     */
    private function createWatchlists(KernelBrowser $client): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
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

        return [
            'owner' => $owner,
            'other' => $other,
            'privateId' => $privateId,
            'publicId' => $publicId,
            'privateName' => $privateName,
            'publicName' => $publicName,
        ];
    }

    private function user(KernelBrowser $client, string $suffix): User
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('video-watchlist-'.$suffix.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Video watchlist '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();

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
