<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewsletterDeliveryReportNavigationTest extends WebTestCase
{
    public function testNewsletterDashboardLinksToTheAggregateDeliveryReport(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $admin = $this->user($client);
        $title = 'Navigation report '.bin2hex(random_bytes(5));
        $campaign = (new NewsletterCampaign())
            ->setCreatedBy($admin)
            ->setTitle($title)
            ->setSubject('Navigation subject')
            ->setBodyText('Navigation body');
        $entityManager->persist($campaign);
        $entityManager->flush();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/newsletter');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header.topbar', 'Auslieferungsbericht');
        $link = $crawler->filter('header.topbar a[href="/admin/newsletter/delivery-report"]');
        self::assertCount(1, $link);

        $client->click($link->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', $title);
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('newsletter-report-navigation-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Newsletter report editor')
            ->setPermissions([CmsPermission::CONTENT])
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
