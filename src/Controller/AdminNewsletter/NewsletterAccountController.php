<?php

declare(strict_types=1);

namespace App\Controller\AdminNewsletter;

use App\Entity\User;
use App\Newsletter\NewsletterConsentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account/newsletter', name: 'app_admin_notification_newsletter_account_')]
#[IsGranted('ROLE_USER')]
final class NewsletterAccountController extends AbstractController
{
    public function __construct(private readonly NewsletterConsentService $consent) {}

    #[Route('/subscribe', name: 'subscribe', methods: ['POST'])]
    public function subscribe(Request $request): Response
    {
        $this->assertCsrf($request, 'newsletter-account-subscribe');
        try {
            $this->consent->requestForUser($this->requireUser(), new \DateTimeImmutable());
            $this->addFlash('success', 'Bitte bestätige die Anmeldung über die versendete E-Mail.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_notification_preferences_index');
    }

    #[Route('/unsubscribe', name: 'unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): Response
    {
        $this->assertCsrf($request, 'newsletter-account-unsubscribe');
        $this->consent->unsubscribeForUser($this->requireUser(), new \DateTimeImmutable());
        $this->addFlash('success', 'Newsletter abbestellt.');

        return $this->redirectToRoute('app_admin_notification_preferences_index');
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
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
}
