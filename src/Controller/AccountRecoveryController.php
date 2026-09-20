<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Form\ForgotPasswordType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Service\AccountMailer;
use App\Service\AccountTokenManager;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AccountRecoveryController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSessionRepository $sessions,
        private readonly AccountTokenManager $tokens,
        private readonly AccountMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.account_recovery')] private readonly RateLimiterFactory $recoveryLimiter,
    ) {}

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        $form = $this->createForm(ForgotPasswordType::class)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $limitKey = 'reset-'.($request->getClientIp() ?? 'unknown');
            $accepted = $this->recoveryLimiter->create($limitKey)->consume()->isAccepted();
            $email = mb_strtolower(trim((string) $form->get('email')->getData()));
            $user = $accepted ? $this->users->findOneBy(['email' => $email, 'isActive' => true]) : null;

            if ($user instanceof User) {
                [, $plainToken] = $this->tokens->issue($user, AccountToken::PURPOSE_PASSWORD_RESET, new \DateInterval('PT1H'));
                $this->audit->record('security.password_reset.requested', $user, $user->getId(), 'Passwort-Zurücksetzung angefordert.');
                $this->entityManager->flush();
                try {
                    $this->mailer->sendPasswordReset($user, $plainToken);
                } catch (\Throwable $exception) {
                    $this->logger->error('Password reset email delivery failed.', ['user_id' => $user->getId(), 'exception' => $exception::class]);
                }
            }

            $this->addFlash('success', 'Wenn ein aktives Konto zu dieser Adresse existiert, wurde eine Nachricht versendet.');
            return $this->redirectToRoute('app_forgot_password');
        }

        return $this->render('security/forgot_password.html.twig', ['form' => $form]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function resetPassword(string $token, Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        $accountToken = $this->tokens->resolve($token, AccountToken::PURPOSE_PASSWORD_RESET);
        if ($accountToken === null || !$accountToken->getUser()?->isActive()) {
            return $this->render('security/reset_password.html.twig', ['invalid' => true, 'form' => null]);
        }

        $form = $this->createForm(ResetPasswordType::class)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $accountToken = $this->tokens->consume($token, AccountToken::PURPOSE_PASSWORD_RESET);
            $user = $accountToken?->getUser();
            if (!$user instanceof User || !$user->isActive()) {
                return $this->render('security/reset_password.html.twig', ['invalid' => true, 'form' => null]);
            }

            $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('password')->getData()));
            $user->invalidateSessions();
            $this->sessions->revokeAll($user);
            $this->tokens->revoke($user, AccountToken::PURPOSE_PASSWORD_RESET);
            $this->audit->record('security.password_reset.completed', $user, $user->getId(), 'Passwort erfolgreich zurückgesetzt; bestehende Sitzungen wurden ungültig gemacht.');
            $this->entityManager->flush();
            $this->addFlash('success', 'Das Passwort wurde geändert. Du kannst dich jetzt anmelden.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', ['invalid' => false, 'form' => $form]);
    }

    #[Route('/account/security/email/send', name: 'app_send_email_verification', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendVerification(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('send-email-verification', (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        if ($user->isEmailVerified()) {
            $this->addFlash('success', 'Deine E-Mail-Adresse ist bereits bestätigt.');
            return $this->redirectToRoute('app_account_security');
        }

        $accepted = $this->recoveryLimiter->create('verify-'.$user->getId())->consume()->isAccepted();
        if (!$accepted) {
            $this->addFlash('error', 'Bitte warte, bevor du eine weitere Bestätigungsnachricht anforderst.');
            return $this->redirectToRoute('app_account_security');
        }

        [, $plainToken] = $this->tokens->issue($user, AccountToken::PURPOSE_EMAIL_VERIFICATION, new \DateInterval('P1D'));
        $this->audit->record('security.email_verification.requested', $user, $user->getId(), 'E-Mail-Bestätigung angefordert.');
        $this->entityManager->flush();
        try {
            $this->mailer->sendVerification($user, $plainToken);
            $this->addFlash('success', 'Die Bestätigungsnachricht wurde versendet.');
        } catch (\Throwable $exception) {
            $this->logger->error('Verification email delivery failed.', ['user_id' => $user->getId(), 'exception' => $exception::class]);
            $this->addFlash('error', 'Die Nachricht konnte momentan nicht versendet werden.');
        }

        return $this->redirectToRoute('app_account_security');
    }

    #[Route('/verify-email/{token}', name: 'app_verify_email', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function verifyEmail(string $token): Response
    {
        $accountToken = $this->tokens->consume($token, AccountToken::PURPOSE_EMAIL_VERIFICATION);
        $user = $accountToken?->getUser();
        if (!$user instanceof User) {
            return $this->render('security/email_verified.html.twig', ['success' => false]);
        }

        $user->verifyEmail();
        $this->tokens->revoke($user, AccountToken::PURPOSE_EMAIL_VERIFICATION);
        $this->audit->record('security.email.verified', $user, $user->getId(), 'E-Mail-Adresse bestätigt.');
        $this->entityManager->flush();
        return $this->render('security/email_verified.html.twig', ['success' => true]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
