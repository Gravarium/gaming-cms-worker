<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PublicNewsletterSignupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class PublicNewsletterSignupController extends AbstractController
{
    public function __construct(
        private readonly PublicNewsletterSignupService $signup,
        #[Autowire(service: 'limiter.public_newsletter_signup_ip')]
        private readonly RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.public_newsletter_signup_email')]
        private readonly RateLimiterFactory $emailLimiter,
    ) {
    }

    #[Route('/newsletter/subscribe', name: 'app_admin_notification_public_newsletter_signup', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->page();
        }

        if (!$this->isCsrfTokenValid('public-newsletter-signup', $request->request->getString('_token'))) {
            return $this->page(
                ['form' => 'Die Sicherheitsprüfung ist fehlgeschlagen. Bitte lade die Seite neu.'],
                '',
                Response::HTTP_FORBIDDEN,
            );
        }

        $email = mb_strtolower(trim($request->request->getString('email')));
        $ipKey = 'ip-'.($request->getClientIp() ?? 'unknown');
        if (!$this->ipLimiter->create($ipKey)->consume()->isAccepted()) {
            return $this->page(
                ['form' => 'Zu viele Anfragen. Bitte warte eine Weile und versuche es erneut.'],
                '',
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $errors = [];
        if ($email === '' || mb_strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Gib eine gültige E-Mail-Adresse ein.';
        }
        if ($request->request->getString('consent') !== 'yes') {
            $errors['consent'] = 'Bitte bestätige, dass du den Newsletter erhalten möchtest.';
        }
        if ($errors !== []) {
            return $this->page($errors, $email, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $emailKey = 'email-'.hash('sha256', $email);
        if (!$this->emailLimiter->create($emailKey)->consume()->isAccepted()) {
            return $this->page(
                ['form' => 'Diese Anmeldung wurde zu oft angefragt. Bitte warte eine Weile und versuche es erneut.'],
                $email,
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        if (!$this->signup->requestConfirmation($email, new \DateTimeImmutable())) {
            return $this->page(
                ['form' => 'Die Bestätigungs-E-Mail konnte gerade nicht verschickt werden. Bitte versuche es später erneut.'],
                $email,
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $this->addFlash(
            'success',
            'Wenn die Adresse für den Newsletter verwendet werden kann, senden wir eine Bestätigung. Bitte bestätige die Anmeldung über den Link in deiner E-Mail.',
        );

        $response = $this->redirectToRoute(
            'app_admin_notification_public_newsletter_signup',
            [],
            Response::HTTP_SEE_OTHER,
        );
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @param array<string, string> $errors */
    private function page(array $errors = [], string $email = '', int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('@PublicNewsletterSignup/signup.html.twig', [
            'errors' => $errors,
            'email' => $email,
        ]);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
