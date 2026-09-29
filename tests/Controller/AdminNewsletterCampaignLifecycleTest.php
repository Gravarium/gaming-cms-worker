<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterDelivery;
use App\Entity\Newsletter\NewsletterSubscription;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminNewsletterCampaignLifecycleTest extends WebTestCase
{
    public function testScheduledCampaignCanBeRescheduledAndCancelled(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $admin = $this->user($em, [CmsPermission::CONTENT]);
        $campaign = $this->campaign($em, $admin);
        $campaign->schedule(new \DateTimeImmutable('+2 days'), new \DateTimeImmutable());
        $em->flush();
        $id = $campaign->getId();
        self::assertIsInt($id);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/newsletter');
        self::assertResponseIsSuccessful();
        $action = '/admin/newsletter/'.$id.'/reschedule';
        $token = (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');

        $client->request('POST', $action, ['_token' => $token, 'scheduled_at' => '2026-02-31T12:00']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(NewsletterCampaign::STATUS_SCHEDULED, $campaign->getStatus());

        $future = new \DateTimeImmutable('+3 days', new \DateTimeZone('UTC'));
        $value = $future->format('Y-m-d\\TH:i');
        $client->request('POST', $action, ['_token' => $token, 'scheduled_at' => $value]);
        self::assertResponseRedirects('/admin/newsletter');
        $em = $this->em($client);
        $campaign = $em->find(NewsletterCampaign::class, $id);
        self::assertInstanceOf(NewsletterCampaign::class, $campaign);
        self::assertSame($value, $campaign->getScheduledAt()?->format('Y-m-d\\TH:i'));

        $crawler = $client->request('GET', '/admin/newsletter');
        $cancel = '/admin/newsletter/'.$id.'/cancel';
        $cancelToken = (string) $crawler->filter('form[action="'.$cancel.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $cancel, ['_token' => $cancelToken]);
        self::assertResponseRedirects('/admin/newsletter');
        $em = $this->em($client);
        $campaign = $em->find(NewsletterCampaign::class, $id);
        self::assertInstanceOf(NewsletterCampaign::class, $campaign);
        self::assertSame(NewsletterCampaign::STATUS_CANCELLED, $campaign->getStatus());
        self::assertNull($campaign->getScheduledAt());

        $client->request('POST', $cancel, ['_token' => $cancelToken]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(NewsletterCampaign::STATUS_CANCELLED, $campaign->getStatus());
    }

    public function testRetryRequeuesOnlyTerminalFailuresWithoutTouchingSentOrSuppressed(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $admin = $this->user($em, [CmsPermission::CONTENT]);
        $campaign = $this->campaign($em, $admin);
        $failed = $this->delivery($em, $campaign, 'failed');
        $sent = $this->delivery($em, $campaign, 'sent');
        $suppressed = $this->delivery($em, $campaign, 'suppressed');
        $now = new \DateTimeImmutable();
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $failed->markFailure('transport_unavailable', $now);
        }
        $sent->markSent($now);
        $suppressed->suppress($now);
        $campaign->markFailed($now);
        $em->flush();
        $failedId = $failed->getId();
        $sentId = $sent->getId();
        $suppressedId = $suppressed->getId();
        self::assertIsInt($failedId);
        self::assertIsInt($sentId);
        self::assertIsInt($suppressedId);
        $id = $campaign->getId();
        self::assertIsInt($id);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/newsletter');
        self::assertResponseIsSuccessful();
        $action = '/admin/newsletter/'.$id.'/retry';
        $token = (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
        $future = (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i');
        $client->request('POST', $action, ['_token' => $token, 'scheduled_at' => $future]);
        self::assertResponseRedirects('/admin/newsletter');

        $em = $this->em($client);
        $campaign = $em->find(NewsletterCampaign::class, $id);
        $failed = $em->find(NewsletterDelivery::class, $failedId);
        $sent = $em->find(NewsletterDelivery::class, $sentId);
        $suppressed = $em->find(NewsletterDelivery::class, $suppressedId);
        self::assertInstanceOf(NewsletterCampaign::class, $campaign);
        self::assertInstanceOf(NewsletterDelivery::class, $failed);
        self::assertInstanceOf(NewsletterDelivery::class, $sent);
        self::assertInstanceOf(NewsletterDelivery::class, $suppressed);
        self::assertSame(NewsletterCampaign::STATUS_SCHEDULED, $campaign->getStatus());
        self::assertSame($future, $campaign->getScheduledAt()?->format('Y-m-d\\TH:i'));
        self::assertSame(NewsletterDelivery::STATUS_PENDING, $failed->getStatus());
        self::assertSame(0, $failed->getAttempts());
        self::assertNull($failed->getLastFailureCode());
        self::assertSame(NewsletterDelivery::STATUS_SENT, $sent->getStatus());
        self::assertSame(NewsletterDelivery::STATUS_SUPPRESSED, $suppressed->getStatus());

        $client->request('POST', $action, ['_token' => $token, 'scheduled_at' => $future]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(NewsletterDelivery::STATUS_PENDING, $failed->getStatus());
    }

    public function testPermissionCsrfAndModuleGateProtectLifecycle(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $admin = $this->user($em, [CmsPermission::CONTENT]);
        $viewer = $this->user($em, []);
        $campaign = $this->campaign($em, $admin);
        $id = $campaign->getId();
        self::assertIsInt($id);
        $action = '/admin/newsletter/'.$id.'/cancel';

        $client->loginUser($viewer);
        $client->request('POST', $action);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($admin);
        $client->request('POST', $action);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(NewsletterCampaign::STATUS_DRAFT, $campaign->getStatus());

        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'notifications');
        $created = !$state instanceof CmsModuleState;
        $wasEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())->setModuleKey('notifications');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();
        try {
            $client->request('POST', $action);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(NewsletterCampaign::STATUS_DRAFT, $campaign->getStatus());
        } finally {
            $em = $this->em($client);
            $state = $em->find(CmsModuleState::class, 'notifications');
            self::assertInstanceOf(CmsModuleState::class, $state);
            if ($created) {
                $em->remove($state);
            } else {
                $state->setEnabled($wasEnabled);
            }
            $em->flush();
        }
    }

    private function user(EntityManagerInterface $em, array $permissions): User
    {
        $user = (new User())
            ->setEmail('newsletter-lifecycle-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Newsletter lifecycle')
            ->setPermissions($permissions)
            ->verifyEmail();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function campaign(EntityManagerInterface $em, User $admin): NewsletterCampaign
    {
        $campaign = (new NewsletterCampaign())
            ->setCreatedBy($admin)
            ->setTitle('Lifecycle '.bin2hex(random_bytes(4)))
            ->setSubject('Subject')
            ->setBodyText('Body');
        $em->persist($campaign);
        $em->flush();

        return $campaign;
    }

    private function delivery(EntityManagerInterface $em, NewsletterCampaign $campaign, string $label): NewsletterDelivery
    {
        $subscription = (new NewsletterSubscription())->setEmail('newsletter-'.$label.'-'.bin2hex(random_bytes(8)).'@example.test');
        $delivery = new NewsletterDelivery($campaign, $subscription);
        $em->persist($subscription);
        $em->persist($delivery);

        return $delivery;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
