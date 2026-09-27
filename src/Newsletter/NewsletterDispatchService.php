<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterDelivery;
use App\ExternalConnector\ExternalMailDispatcher;
use App\ExternalConnector\ExternalMailMessage;
use App\Repository\Newsletter\NewsletterDeliveryRepository;
use App\Repository\Newsletter\NewsletterSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final readonly class NewsletterDispatchService
{
    public function __construct(
        private NewsletterSubscriptionRepository $subscriptions,
        private NewsletterDeliveryRepository $deliveries,
        private EntityManagerInterface $entityManager,
        private ExternalMailDispatcher $mail,
        private RouterInterface $router,
    ) {}

    public function dispatch(NewsletterCampaign $campaign, \DateTimeImmutable $now, int $runQuota = 100): NewsletterDispatchResult
    {
        if ($runQuota < 1 || $runQuota > 1000) {
            throw new \InvalidArgumentException('Newsletter run quota must be between 1 and 1000.');
        }
        if (!$campaign->canDispatch($now)) {
            throw new \DomainException('Newsletter campaign is not due for dispatch.');
        }

        $campaign->markSending($now);
        foreach ($this->subscriptions->activeForSegment($campaign->getSegment(), $campaign->getMaxRecipients()) as $subscription) {
            if (!$this->deliveries->existsFor($campaign, $subscription)) {
                $this->entityManager->persist(new NewsletterDelivery($campaign, $subscription));
            }
        }
        $this->entityManager->flush();

        $sent = 0;
        $failedAttempts = 0;
        $suppressed = 0;

        foreach ($this->deliveries->dispatchable($campaign, $now, $runQuota) as $delivery) {
            $subscription = $delivery->getSubscription();
            if (!$subscription->canReceive()) {
                $delivery->suppress($now);
                ++$suppressed;
                continue;
            }

            $token = $subscription->issueUnsubscribeToken($now);
            $unsubscribeUrl = $this->router->generate(
                'app_admin_notification_newsletter_public_unsubscribe',
                ['id' => $subscription->getId(), 'token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $summary = $this->mail->send(new ExternalMailMessage(
                [$subscription->getEmail()],
                $campaign->getSubject(),
                $campaign->getBodyText()."\n\nNewsletter abbestellen: ".$unsubscribeUrl,
            ));

            if ($summary->successfulCount() > 0) {
                $delivery->markSent($now);
                ++$sent;
            } else {
                $delivery->markFailure('transport_unavailable', $now);
                ++$failedAttempts;
            }
        }

        $this->entityManager->flush();
        $outstanding = $this->deliveries->countOutstanding($campaign);
        if ($outstanding === 0) {
            if ($this->deliveries->countFailed($campaign) > 0) {
                $campaign->markFailed($now);
            } else {
                $campaign->markSent($now);
            }
            $this->entityManager->flush();
        }

        return new NewsletterDispatchResult($sent, $failedAttempts, $suppressed, $outstanding, $campaign->getStatus());
    }
}
