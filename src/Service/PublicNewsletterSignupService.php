<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Newsletter\NewsletterSubscription;
use App\ExternalConnector\ExternalMailDispatcher;
use App\ExternalConnector\ExternalMailMessage;
use App\Repository\Newsletter\NewsletterSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final readonly class PublicNewsletterSignupService
{
    public function __construct(
        private NewsletterSubscriptionRepository $subscriptions,
        private EntityManagerInterface $entityManager,
        private ExternalMailDispatcher $mail,
        private RouterInterface $router,
        private LoggerInterface $logger,
    ) {
    }

    public function requestConfirmation(string $email, \DateTimeImmutable $now): bool
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180) {
            throw new \InvalidArgumentException('Invalid newsletter email.');
        }

        $subscription = $this->subscriptions->findByEmail($email);
        if ($subscription === null) {
            $subscription = (new NewsletterSubscription())->setEmail($email);
            $this->entityManager->persist($subscription);
        }

        if ($subscription->getStatus() === NewsletterSubscription::STATUS_SUPPRESSED || $subscription->canReceive()) {
            return true;
        }

        try {
            $token = $subscription->issueConfirmation('public_form', 'v1', $now);
        } catch (\DomainException) {
            // Keep suppressed addresses indistinguishable from new addresses.
            return true;
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('Public newsletter consent could not be saved.', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        $id = $subscription->getId();
        if ($id === null) {
            $this->logger->error('Public newsletter consent was not persisted.');

            return false;
        }

        $confirmUrl = $this->router->generate(
            'app_admin_notification_newsletter_public_confirm',
            ['id' => $id, 'token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $summary = $this->mail->send(new ExternalMailMessage(
                [$subscription->getEmail()],
                'Newsletter-Anmeldung bestätigen',
                "Bitte bestätige die Newsletter-Anmeldung über diesen einmaligen Link:\n".$confirmUrl,
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Public newsletter confirmation delivery failed.', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        if ($summary->successfulCount() === 0) {
            $this->logger->warning('No configured external mail provider accepted the newsletter confirmation.');

            return false;
        }

        return true;
    }
}
