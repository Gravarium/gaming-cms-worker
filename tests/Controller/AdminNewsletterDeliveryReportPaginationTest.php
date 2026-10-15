<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminNewsletterDeliveryReportPaginationTest extends WebTestCase
{
    public function testManagerCanContinueThroughStablePagesWithoutSkippedOrRepeatedCampaigns(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $manager = (new User())
            ->setEmail('delivery-pagination-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Delivery pagination manager')
            ->setPassword('unused')
            ->setPermissions([CmsPermission::CONTENT])
            ->verifyEmail();
        $entityManager->persist($manager);

        $createdAt = new \DateTimeImmutable('2099-05-04 12:13:14', new \DateTimeZone('UTC'));
        $createdAtProperty = new \ReflectionProperty(NewsletterCampaign::class, 'createdAt');
        $campaignTitlesById = [];
        for ($index = 1; $index <= 27; ++$index) {
            $title = sprintf('Pagination campaign %02d %s', $index, bin2hex(random_bytes(3)));
            $campaign = (new NewsletterCampaign())
                ->setCreatedBy($manager)
                ->setTitle($title)
                ->setSubject('Private subject '.$title)
                ->setBodyText('Private body '.$title);
            $createdAtProperty->setValue($campaign, $createdAt);
            $entityManager->persist($campaign);
            $campaignTitlesById[] = [$campaign, $title];
        }
        $entityManager->flush();

        $campaignIds = [];
        $managerId = $manager->getId();
        try {
            $expectedTitles = [];
            foreach ($campaignTitlesById as [$campaign, $title]) {
                self::assertInstanceOf(NewsletterCampaign::class, $campaign);
                $campaignId = $campaign->getId();
                self::assertNotNull($campaignId);
                $campaignIds[] = $campaignId;
                $expectedTitles[$campaignId] = $title;
            }
            krsort($expectedTitles, SORT_NUMERIC);
            $expectedTitles = array_values($expectedTitles);
            $client->loginUser($manager);

            $firstPage = $client->request('GET', '/admin/newsletter/delivery-report');
            self::assertResponseIsSuccessful();
            self::assertSame(array_slice($expectedTitles, 0, 25), $this->campaignTitles($firstPage));
            self::assertSelectorExists('a[rel="next"][aria-label="Ältere Kampagnen"]');
            self::assertSelectorNotExists('a[aria-label="Neueste Kampagnen"]');

            $firstBody = (string) $client->getResponse()->getContent();
            foreach ($campaignTitlesById as [, $title]) {
                self::assertStringNotContainsString('Private subject '.$title, $firstBody);
                self::assertStringNotContainsString('Private body '.$title, $firstBody);
            }

            $nextPageHref = (string) $firstPage->filter('a[rel="next"]')->attr('href');
            $secondPage = $client->request('GET', $nextPageHref);
            self::assertResponseIsSuccessful();
            self::assertSame(array_slice($expectedTitles, 25), $this->campaignTitles($secondPage));
            self::assertSelectorNotExists('a[rel="next"]');
            self::assertSelectorExists('a[aria-label="Neueste Kampagnen"]');

            $allVisibleTitles = array_merge(
                array_slice($expectedTitles, 0, 25),
                $this->campaignTitles($secondPage),
            );
            self::assertSame($expectedTitles, $allVisibleTitles);
            self::assertCount(count($allVisibleTitles), array_unique($allVisibleTitles));
            $secondBody = (string) $client->getResponse()->getContent();
            foreach ($campaignTitlesById as [, $title]) {
                self::assertStringNotContainsString('Private subject '.$title, $secondBody);
                self::assertStringNotContainsString('Private body '.$title, $secondBody);
            }
        } finally {
            $entityManager->clear();
            if ($campaignIds !== []) {
                $entityManager->createQueryBuilder()
                    ->delete(NewsletterCampaign::class, 'campaign')
                    ->where('campaign.id IN (:ids)')
                    ->setParameter('ids', $campaignIds)
                    ->getQuery()
                    ->execute();
            }

            if ($managerId !== null) {
                $storedManager = $entityManager->find(User::class, $managerId);
                if ($storedManager instanceof User) {
                    $entityManager->remove($storedManager);
                    $entityManager->flush();
                }
            }
        }
    }

    public function testMalformedCursorAndUnexpectedQueryValuesReturnPrivateGenericBadRequest(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $manager = (new User())
            ->setEmail('delivery-pagination-invalid-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Delivery pagination manager')
            ->setPassword('unused')
            ->setPermissions([CmsPermission::CONTENT])
            ->verifyEmail();
        $entityManager->persist($manager);
        $entityManager->flush();
        $managerId = $manager->getId();
        try {
            $client->loginUser($manager);

            $invalidDateCursor = rtrim(strtr(base64_encode('2099-02-30T12:13:14.000000|1'), '+/', '-_'), '=');
            foreach ([
                '/admin/newsletter/delivery-report?after=not-a-cursor',
                '/admin/newsletter/delivery-report?after%5B%5D=one',
                '/admin/newsletter/delivery-report?after='.rawurlencode($invalidDateCursor),
                '/admin/newsletter/delivery-report?unexpected=value',
            ] as $url) {
                $client->request('GET', $url);

                self::assertResponseStatusCodeSame(400);
                $cacheControlDirectives = array_map('trim', explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))));
                self::assertContains('private', $cacheControlDirectives);
                self::assertContains('no-store', $cacheControlDirectives);
                self::assertSame('noindex', $client->getResponse()->headers->get('X-Robots-Tag'));
                self::assertSame('Ungültige Seitennavigation.', $client->getResponse()->getContent());
            }
        } finally {
            $entityManager->clear();
            if ($managerId !== null) {
                $storedManager = $entityManager->find(User::class, $managerId);
                if ($storedManager instanceof User) {
                    $entityManager->remove($storedManager);
                    $entityManager->flush();
                }
            }
        }
    }

    /** @return list<string> */
    private function campaignTitles(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('tbody th[scope="row"]')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $row): string => trim($row->text('')),
        );
    }
}
