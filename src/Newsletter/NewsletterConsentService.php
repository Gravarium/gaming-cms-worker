<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Entity\Newsletter\NewsletterSubscription;
use App\Entity\User;
use App\ExternalConnector\ExternalMailDispatcher;
use App\ExternalConnector\ExternalMailMessage;
use App\Repository\Newsletter\NewsletterSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final readonly class NewsletterConsentService
{
    public function __construct(
        private NewsletterSubscriptionRepository $subscriptions,
        private EntityManagerInterface $entityManager,
        private ExternalMailDispatcher $mail,
        private RouterInterface $router,
    ) {}

    public function requestForUser(User $user, \DateTimeImmutable $now): NewsletterConsentChallenge
    {
        $userId = $user->getId();
        if ($userId === null || !$user->isEmailVerified()) {
            throw new \DomainException('A verified persisted account is required for newsletter consent.');
        }

        $subscription = $this->subscriptions->findByEmail($user->getEmail());
        if ($subscription === null) {
            $subscription = (new NewsletterSubscription())
                ->setEmail($user->getEmail())
                ->setUser($user);
            $this->entityManager->persist($subscription);
        } else {
            $subscription->setUser($user);
        }

        $token = $subscription->issueConfirmation('account', 'v1', $now);
        $this->entityManager->flush();

        $id = $subscription->getId();
        if ($id === null) {
            throw new \LogicException('Newsletter subscription was not persisted.');
        }

        $confirmUrl = $this->router->generate(
            'app_admin_notification_newsletter_public_confirm',
            ['id' => $id, 'token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $summary = $this->mail->send(new ExternalMailMessage(
            [$subscription->getEmail()],
            'Newsletter-Anmeldung bestätigen',
            "Bitte bestätige die Newsletter-Anmeldung über diesen einmaligen Link:\n".$confirmUrl,
        ));

        if ($summary->successfulCount() === 0) {
            throw new \DomainException('Newsletter confirmation mail could not be delivered.');
        }

        return new NewsletterConsentChallenge($id, $subscription->getEmail(), $token);
    }

    public function confirm(int $subscriptionId, string $token, \DateTimeImmutable $now): NewsletterSubscription
    {
        $subscription = $this->subscriptions->find($subscriptionId);
        if (!$subscription instanceof NewsletterSubscription) {
            throw new \DomainException('Invalid newsletter confirmation.');
        }

        $subscription->confirm($token, $now);
        $this->entityManager->flush();

        return $subscription;
    }

    public function unsubscribeByToken(int $subscriptionId, string $token, \DateTimeImmutable $now): void
    {
        $subscription = $this->subscriptions->find($subscriptionId);
        if (!$subscription instanceof NewsletterSubscription) {
            throw new \DomainException('Invalid newsletter unsubscribe request.');
        }

        $subscription->unsubscribe($token, $now);
        $this->entityManager->flush();
    }

    public function unsubscribeForUser(User $user, \DateTimeImmutable $now): void
    {
        $subscription = $this->subscriptions->findByEmail($user->getEmail());
        if (!$subscription instanceof NewsletterSubscription || ($subscription->getUser() !== null && $subscription->getUser() !== $user)) {
            return;
        }

        $subscription->unsubscribeForAccount($now);
        $this->entityManager->flush();
    }
}
