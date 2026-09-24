<?php

declare(strict_types=1);

namespace App\Tests\Newsletter;

use App\Entity\CmsModuleState;
use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewsletterAdminSecurityTest extends WebTestCase
{
    public function testNewsletterAdministrationRequiresContentPermission(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, []));

        $client->request('GET', '/admin/newsletter');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDispatchActionRejectsMissingCsrf(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $campaign = (new NewsletterCampaign())
            ->setCreatedBy($admin)
            ->setTitle('Security')
            ->setSubject('Subject')
            ->setBodyText('Body');
        $this->em($client)->persist($campaign);
        $this->em($client)->flush();
        self::assertNotNull($campaign->getId());
        $client->loginUser($admin);

        $client->request('POST', '/admin/newsletter/'.$campaign->getId().'/dispatch', ['quota' => '10']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(NewsletterCampaign::STATUS_DRAFT, $campaign->getStatus());
    }

    public function testDisabledNotificationModuleHidesNewsletterRoutes(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $state = (new CmsModuleState())->setModuleKey('notifications')->setEnabled(false);
        $this->em($client)->persist($state);
        $this->em($client)->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/newsletter');
        self::assertResponseStatusCodeSame(404);
    }

    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('newsletter-security-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Newsletter security')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();
        return $user;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
