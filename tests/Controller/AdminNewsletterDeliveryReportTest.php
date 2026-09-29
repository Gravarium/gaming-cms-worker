<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterDelivery;
use App\Entity\Newsletter\NewsletterSubscription;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Repository\Newsletter\NewsletterCampaignRepository;
use App\Repository\Newsletter\NewsletterDeliveryRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminNewsletterDeliveryReportTest extends WebTestCase
{
    public function testAuthorizedManagerSeesAggregateDeliveryStatusesWithoutRecipientData(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $now = new \DateTimeImmutable();
        $title = 'Report campaign '.bin2hex(random_bytes(5));
        $campaign = (new NewsletterCampaign())
            ->setCreatedBy($admin)
            ->setTitle($title)
            ->setSubject('Internal subject')
            ->setBodyText('Internal newsletter body');
        $campaign->markSending($now);
        $entityManager->persist($campaign);

        $emails = [];
        foreach (['sent', 'pending', 'retry', 'failed', 'suppressed'] as $kind) {
            $email = $this->email($kind);
            $emails[] = $email;
            $subscription = (new NewsletterSubscription())->setEmail($email);
            $delivery = new NewsletterDelivery($campaign, $subscription);

            switch ($kind) {
                case 'sent':
                    $delivery->markSent($now);
                    break;
                case 'retry':
                    $delivery->markFailure('transport_unavailable', $now);
                    break;
                case 'failed':
                    for ($attempt = 0; $attempt < 5; ++$attempt) {
                        $delivery->markFailure('transport_unavailable', $now);
                    }
                    break;
                case 'suppressed':
                    $delivery->suppress($now);
                    break;
            }

            $entityManager->persist($subscription);
            $entityManager->persist($delivery);
        }

        $entityManager->flush();
        $campaignId = $campaign->getId();
        self::assertNotNull($campaignId);
        $client->loginUser($admin);

        $client->request('GET', '/admin/newsletter/delivery-report');

        self::assertResponseIsSuccessful();
        $cacheControlDirectives = array_map('trim', explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))));
        self::assertContains('private', $cacheControlDirectives);
        self::assertContains('no-store', $cacheControlDirectives);
        self::assertSame('noindex', $client->getResponse()->headers->get('X-Robots-Tag'));

        $row = $client->getCrawler()->filter('tbody tr')->reduce(
            static fn (Crawler $candidate): bool => str_contains($candidate->text(''), $title),
        );
        self::assertCount(1, $row);
        self::assertSame(
            [$title, 'Versand läuft', '—', '1', '1', '1', '1', '1'],
            $row->filter('th, td')->each(static fn (Crawler $cell): string => trim($cell->text(''))),
        );

        $responseBody = (string) $client->getResponse()->getContent();
        foreach ($emails as $email) {
            self::assertStringNotContainsString($email, $responseBody);
        }
        self::assertStringNotContainsString('Internal subject', $responseBody);
        self::assertStringNotContainsString('Internal newsletter body', $responseBody);

        $entityManager->clear();
        $storedCampaign = $this->campaigns($client)->find($campaignId);
        self::assertInstanceOf(NewsletterCampaign::class, $storedCampaign);
        self::assertSame(NewsletterCampaign::STATUS_SENDING, $storedCampaign->getStatus());

        $deliveries = $this->deliveries($client)->findBy(['campaign' => $storedCampaign]);
        $actualStatuses = array_count_values(array_map(
            static fn (NewsletterDelivery $delivery): string => $delivery->getStatus(),
            $deliveries,
        ));
        $expectedStatuses = [
            NewsletterDelivery::STATUS_FAILED => 1,
            NewsletterDelivery::STATUS_PENDING => 1,
            NewsletterDelivery::STATUS_RETRY => 1,
            NewsletterDelivery::STATUS_SENT => 1,
            NewsletterDelivery::STATUS_SUPPRESSED => 1,
        ];
        ksort($actualStatuses);
        ksort($expectedStatuses);
        self::assertSame($expectedStatuses, $actualStatuses);
    }

    public function testNewsletterDeliveryReportRequiresContentPermission(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, []));

        $client->request('GET', '/admin/newsletter/delivery-report');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewsletterDeliveryReportIsHiddenWhenNotificationsModuleIsDisabled(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $stateRepository = $entityManager->getRepository(CmsModuleState::class);
        $originalState = $stateRepository->find('notifications');
        $originalEnabled = $originalState?->isEnabled() ?? true;
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        $modules->setEnabled('notifications', false);
        $client->loginUser($admin);

        try {
            $client->request('GET', '/admin/newsletter/delivery-report');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $state = $stateRepository->find('notifications');
            if ($originalState === null) {
                if ($state instanceof CmsModuleState) {
                    $entityManager->remove($state);
                }
            } elseif ($state instanceof CmsModuleState) {
                $state->setEnabled($originalEnabled);
                $entityManager->persist($state);
            }
            $entityManager->flush();
        }
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail($this->email('editor'))
            ->setDisplayName('Delivery report editor')
            ->setPermissions($permissions)
            ->verifyEmail();

        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function email(string $prefix): string
    {
        return $prefix.'-'.bin2hex(random_bytes(6)).'@example.test';
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function campaigns(KernelBrowser $client): NewsletterCampaignRepository
    {
        return $client->getContainer()->get(NewsletterCampaignRepository::class);
    }

    private function deliveries(KernelBrowser $client): NewsletterDeliveryRepository
    {
        return $client->getContainer()->get(NewsletterDeliveryRepository::class);
    }
}
