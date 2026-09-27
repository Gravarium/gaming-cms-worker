<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterSubscription;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminNewsletterSubscriptionDirectoryTest extends WebTestCase
{
    public function testDirectorySearchesFiltersAndPaginatesWithoutExposingSecrets(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $client->loginUser($this->user($client, [CmsPermission::CONTENT]));
        $now = new \DateTimeImmutable();
        $suffix = bin2hex(random_bytes(5));
        $directorySuffix = bin2hex(random_bytes(5));
        $subscriptions = [];
        $subscriptionIds = [];

        try {
            for ($index = 0; $index < 53; ++$index) {
                $subscription = $this->activeSubscription(
                    sprintf('directory-%02d-%s@example.test', $index, $directorySuffix),
                    $now,
                );
                $entityManager->persist($subscription);
                $subscriptions[] = $subscription;
            }

            $activeMatch = $this->activeSubscription('search-target-'.$suffix.'@example.test', $now);
            $entityManager->persist($activeMatch);
            $subscriptions[] = $activeMatch;

            $pending = (new NewsletterSubscription())->setEmail('pending-target-'.$suffix.'@example.test');
            $pendingToken = $pending->issueConfirmation('public_signup', 'v1', $now);
            $entityManager->persist($pending);
            $subscriptions[] = $pending;

            $unsubscribed = (new NewsletterSubscription())
                ->setEmail('unsubscribed-target-'.$suffix.'@example.test');
            $unsubscribed->unsubscribeForAccount($now);
            $entityManager->persist($unsubscribed);
            $subscriptions[] = $unsubscribed;

            $suppressionReason = 'private-test-reason-'.$suffix;
            $suppressed = (new NewsletterSubscription())
                ->setEmail('suppressed-target-'.$suffix.'@example.test');
            $suppressed->suppress($suppressionReason, $now);
            $entityManager->persist($suppressed);
            $subscriptions[] = $suppressed;
            $entityManager->flush();
            foreach ($subscriptions as $subscription) {
                $id = $subscription->getId();
                if ($id !== null) {
                    $subscriptionIds[] = $id;
                }
            }

            $client->request(
                'GET',
                '/admin/newsletter/subscriptions?email=SEARCH-TARGET-'.$suffix.'&status=active',
            );
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('tbody', 'search-target-'.$suffix.'@example.test');
            self::assertSelectorTextNotContains('tbody', 'directory-');

            $client->request(
                'GET',
                '/admin/newsletter/subscriptions?email='.$directorySuffix.'&status=active&page=2',
            );
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Seite 2 von 2');
            self::assertCount(3, $client->getCrawler()->filter('tbody tr'));
            $previousHref = $client->getCrawler()->filter('a[rel="prev"]')->attr('href');
            self::assertIsString($previousHref);
            self::assertStringContainsString('email='.$directorySuffix, $previousHref);
            self::assertStringContainsString('status=active', $previousHref);

            $client->request(
                'GET',
                '/admin/newsletter/subscriptions?email='.$directorySuffix.'&status=active&page=999',
            );
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Seite 2 von 2');
            self::assertCount(3, $client->getCrawler()->filter('tbody tr'));

            $client->request(
                'GET',
                '/admin/newsletter/subscriptions?email=pending-target-'.$suffix.'&status=pending',
            );
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('tbody', 'Ausstehend');
            $pendingResponse = $client->getResponse()->getContent();
            self::assertIsString($pendingResponse);
            self::assertStringNotContainsString($pendingToken, $pendingResponse);
            self::assertStringNotContainsString(hash('sha256', $pendingToken), $pendingResponse);

            $client->request(
                'GET',
                '/admin/newsletter/subscriptions?email=unsubscribed-target-'.$suffix.'&status=unsubscribed',
            );
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('tbody', 'Abgemeldet');

            $client->request(
                'GET',
                '/admin/newsletter/subscriptions?email=suppressed-target-'.$suffix.'&status=suppressed',
            );
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('tbody', 'Unterdrückt');
            $suppressedResponse = $client->getResponse()->getContent();
            self::assertIsString($suppressedResponse);
            self::assertStringNotContainsString($suppressionReason, $suppressedResponse);

            $client->request(
                'GET',
                '/admin/newsletter/subscriptions?email=missing-'.$suffix.'&status=active',
            );
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Keine Abonnenten passen zu diesen Filtern.');

            $client->request('GET', '/admin/newsletter/subscriptions?status=unknown');
            self::assertResponseStatusCodeSame(400);

            $client->request('GET', '/admin/newsletter/subscriptions?email='.str_repeat('a', 181));
            self::assertResponseStatusCodeSame(400);
        } finally {
            $cleanupEntityManager = $this->entityManager($client);
            foreach ($subscriptionIds as $id) {
                $storedSubscription = $cleanupEntityManager->find(NewsletterSubscription::class, $id);
                if ($storedSubscription instanceof NewsletterSubscription) {
                    $cleanupEntityManager->remove($storedSubscription);
                }
            }
            $cleanupEntityManager->flush();
        }
    }

    public function testCampaignFormAndPreviewLinkToSubscriberDirectory(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($admin);

        $client->request('GET', '/admin/newsletter/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/newsletter/subscriptions"]');

        $campaign = (new NewsletterCampaign())
            ->setCreatedBy($admin)
            ->setTitle('Directory link')
            ->setSubject('Directory link preview')
            ->setBodyText('Preview body');
        $entityManager->persist($campaign);
        $entityManager->flush();
        self::assertNotNull($campaign->getId());

        $client->request('GET', '/admin/newsletter/'.$campaign->getId().'/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/newsletter/subscriptions"]');
    }

    public function testDirectoryRequiresContentPermission(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, []));

        $client->request('GET', '/admin/newsletter/subscriptions');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDisabledNotificationsModuleHidesDirectory(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'notifications');
        $stateExisted = $state instanceof CmsModuleState;
        $wasEnabled = $state?->isEnabled() ?? true;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('notifications');
            $entityManager->persist($state);
        }
        $state->setEnabled(false);
        $entityManager->flush();
        $client->loginUser($this->user($client, [CmsPermission::CONTENT]));

        try {
            $client->request('GET', '/admin/newsletter/subscriptions');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $current = $entityManager->find(CmsModuleState::class, 'notifications');
            if (!$stateExisted) {
                if ($current instanceof CmsModuleState) {
                    $entityManager->remove($current);
                }
            } elseif ($current instanceof CmsModuleState) {
                $current->setEnabled($wasEnabled);
            }
            $entityManager->flush();
        }
    }

    private function activeSubscription(string $email, \DateTimeImmutable $now): NewsletterSubscription
    {
        $subscription = (new NewsletterSubscription())->setEmail($email);
        $token = $subscription->issueConfirmation('public_signup', 'v1', $now);
        $subscription->confirm($token, $now);

        return $subscription;
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('newsletter-directory-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Newsletter directory administrator')
            ->setPermissions($permissions)
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
