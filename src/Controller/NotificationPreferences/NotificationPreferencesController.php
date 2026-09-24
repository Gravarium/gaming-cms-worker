<?php

declare(strict_types=1);

namespace App\Controller\NotificationPreferences;

use App\Entity\User;
use App\Notification\Preferences\NotificationPreference;
use App\Notification\Preferences\NotificationPreferenceStore;
use App\Notification\Preferences\NotificationSubscriptionStore;
use App\Repository\Newsletter\NewsletterSubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account/notifications', name: 'app_admin_notification_preferences_')]
#[IsGranted('ROLE_USER')]
final class NotificationPreferencesController extends AbstractController
{
    public function __construct(
        private readonly NotificationPreferenceStore $preferences,
        private readonly NotificationSubscriptionStore $subscriptions,
        private readonly NewsletterSubscriptionRepository $newsletterSubscriptions,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireUser();
        $id = $this->requireUserId($user);

        return $this->render('account/notifications/index.html.twig', [
            'preference' => $this->preferences->forUser($id),
            'topics' => $this->subscriptions->topicsForUser($id),
            'newsletter_subscription' => $this->newsletterSubscriptions->findByEmail($user->getEmail()),
        ]);
    }

    #[Route('/preferences', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('notification-preferences', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }

        $user = $this->requireUser();
        try {
            $preference = new NotificationPreference(
                $request->request->getBoolean('in_app_enabled'),
                $request->request->getBoolean('email_enabled'),
                $request->request->getBoolean('mentions_enabled'),
                $request->request->getBoolean('subscriptions_enabled'),
                $request->request->getString('digest_frequency'),
                $this->nullable($request->request->getString('quiet_hours_start')),
                $this->nullable($request->request->getString('quiet_hours_end')),
                $request->request->getString('timezone', 'UTC'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('app_admin_notification_preferences_index');
        }

        $this->preferences->save($this->requireUserId($user), $preference, new \DateTimeImmutable());
        $this->addFlash('success', 'Benachrichtigungseinstellungen gespeichert.');

        return $this->redirectToRoute('app_admin_notification_preferences_index');
    }

    #[Route('/topics/subscribe', name: 'topic_subscribe', methods: ['POST'])]
    public function subscribeTopic(Request $request): Response
    {
        $this->assertTopicCsrf($request);
        $this->subscriptions->subscribe(
            $this->requireUserId($this->requireUser()),
            $request->request->getString('topic'),
            new \DateTimeImmutable(),
        );

        return $this->redirectToRoute('app_admin_notification_preferences_index');
    }

    #[Route('/topics/unsubscribe', name: 'topic_unsubscribe', methods: ['POST'])]
    public function unsubscribeTopic(Request $request): Response
    {
        $this->assertTopicCsrf($request);
        $this->subscriptions->unsubscribe(
            $this->requireUserId($this->requireUser()),
            $request->request->getString('topic'),
        );

        return $this->redirectToRoute('app_admin_notification_preferences_index');
    }

    private function assertTopicCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('notification-topic', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function requireUserId(User $user): int
    {
        return $user->getId() ?? throw new \LogicException('Persisted user required.');
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
