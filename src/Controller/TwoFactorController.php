<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Service\AuditLogger;
use App\Service\SensitiveDataCipher;
use App\Service\TotpAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly TotpAuthenticator $totp,
        private readonly SensitiveDataCipher $cipher,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
        private readonly UserSessionRepository $sessions,
        private readonly UserRepository $users,
        #[Autowire(service: 'limiter.two_factor_challenge')]
        private readonly RateLimiterFactory $challengeLimiter,
    ) {}

    #[Route('/login/2fa', name: 'app_two_factor_challenge', methods: ['GET', 'POST'])]
    public function challenge(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user->isTwoFactorEnabled() || $user->getTwoFactorSecret() === null) {
            $request->getSession()->set('two_factor_verified', true);
            return $this->redirectToRoute('app_account_home');
        }

        $lockedUntil = (int) $request->getSession()->get('two_factor_locked_until', 0);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('two-factor-challenge', (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
            if ($lockedUntil > time()) {
                $this->addFlash('error', 'Zu viele Versuche. Bitte später erneut versuchen.');
                return $this->redirectToRoute('app_two_factor_challenge');
            }

            $userId = $user->getId();
            if ($userId === null) {
                throw $this->createAccessDeniedException();
            }
            $limit = $this->challengeLimiter->create('user-'.$userId)->consume();
            if (!$limit->isAccepted()) {
                $this->audit->record('security.2fa.throttled', $user, $userId, 'Zwei-Faktor-Anmeldung wegen zu vieler Versuche blockiert.');
                $this->entityManager->flush();
                $this->addFlash('error', 'Zu viele Versuche. Bitte später erneut versuchen.');

                return $this->redirectToRoute('app_two_factor_challenge');
            }

            $code = strtoupper(trim((string) $request->request->get('code')));
            $valid = $this->totp->verify($this->cipher->decrypt($user->getTwoFactorSecret()), $code);
            $recoveryCodeUsed = false;
            if (!$valid) {
                $expectedSecurityVersion = $user->getSecurityVersion();
                if ($user->consumeRecoveryCode($code)) {
                    $recoveryCodeUsed = $this->users->persistRecoveryCodeConsumption($user, $expectedSecurityVersion, $user->getRecoveryCodeHashes());
                    $valid = $recoveryCodeUsed;
                    if (!$valid) { $this->entityManager->refresh($user); }
                }
            }

            if ($valid) {
                if ($recoveryCodeUsed) { $this->keepCurrentSession($user, $request); }
                $request->getSession()->set('two_factor_verified', true);
                $request->getSession()->remove('two_factor_failures');
                $request->getSession()->remove('two_factor_locked_until');
                $this->audit->record('security.2fa.success', $user, $user->getId(), 'Zwei-Faktor-Anmeldung erfolgreich.');
                $this->entityManager->flush();
                return $this->redirectToRoute('app_account_home');
            }

            $failures = (int) $request->getSession()->get('two_factor_failures', 0) + 1;
            $request->getSession()->set('two_factor_failures', $failures);
            if ($failures >= 5) {
                $request->getSession()->set('two_factor_locked_until', time() + 900);
                $request->getSession()->set('two_factor_failures', 0);
            }
            $this->audit->record('security.2fa.failure', $user, $user->getId(), 'Zwei-Faktor-Anmeldung fehlgeschlagen.');
            $this->entityManager->flush();
            $this->addFlash('error', 'Der Code ist ungültig.');
            return $this->redirectToRoute('app_two_factor_challenge');
        }

        return $this->render('security/two_factor_challenge.html.twig', ['lockedUntil' => $lockedUntil]);
    }

    #[Route('/account/security/2fa/setup', name: 'app_two_factor_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user->isTwoFactorEnabled()) { return $this->redirectToRoute('app_account_security'); }

        $secret = (string) $request->getSession()->get('two_factor_setup_secret');
        if ($secret === '') {
            $secret = $this->totp->generateSecret();
            $request->getSession()->set('two_factor_setup_secret', $secret);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('two-factor-setup', (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
            $code = (string) $request->request->get('code');
            if ($this->totp->verify($secret, $code)) {
                $recoveryCodes = $this->totp->generateRecoveryCodes();
                $user->enableTwoFactor($this->cipher->encrypt($secret), array_map(static fn (string $item): string => password_hash($item, PASSWORD_DEFAULT), $recoveryCodes));
                $this->keepCurrentSession($user, $request);
                $request->getSession()->remove('two_factor_setup_secret');
                $request->getSession()->set('two_factor_verified', true);
                $request->getSession()->set('two_factor_new_recovery_codes', $recoveryCodes);
                $this->audit->record('security.2fa.enabled', $user, $user->getId(), 'Zwei-Faktor-Anmeldung aktiviert.');
                $this->entityManager->flush();
                return $this->redirectToRoute('app_two_factor_recovery_codes');
            }
            $this->addFlash('error', 'Der Bestätigungscode ist ungültig.');
        }

        return $this->render('account/two_factor_setup.html.twig', [
            'secret' => $secret,
            'provisioningUri' => $this->totp->provisioningUri($secret, $user->getEmail(), 'Gaming CMS'),
        ]);
    }

    #[Route('/account/security/2fa/recovery-codes', name: 'app_two_factor_recovery_codes', methods: ['GET'])]
    public function recoveryCodes(Request $request): Response
    {
        $codes = $request->getSession()->get('two_factor_new_recovery_codes');
        if (!is_array($codes) || $codes === []) { return $this->redirectToRoute('app_account_security'); }
        $request->getSession()->remove('two_factor_new_recovery_codes');
        return $this->render('account/two_factor_recovery_codes.html.twig', ['codes' => $codes]);
    }

    #[Route('/account/security/2fa/disable', name: 'app_two_factor_disable', methods: ['POST'])]
    public function disable(Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('two-factor-disable', (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        if (!$passwordHasher->isPasswordValid($user, (string) $request->request->get('password'))) {
            $this->addFlash('error', 'Das Passwort ist nicht korrekt. Zwei-Faktor-Anmeldung blieb aktiv.');
            return $this->redirectToRoute('app_account_security');
        }

        $user->disableTwoFactor();
        $this->keepCurrentSession($user, $request);
        $request->getSession()->set('two_factor_verified', true);
        $this->audit->record('security.2fa.disabled', $user, $user->getId(), 'Zwei-Faktor-Anmeldung deaktiviert.');
        $this->entityManager->flush();
        $this->addFlash('success', 'Die Zwei-Faktor-Anmeldung wurde deaktiviert.');
        return $this->redirectToRoute('app_account_security');
    }

    private function keepCurrentSession(User $user, Request $request): void
    {
        $current = $this->sessions->findBySessionId($request->getSession()->getId());
        $this->sessions->revokeAll($user, $current?->getSessionHash());
        $current?->syncSecurityVersion();
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
