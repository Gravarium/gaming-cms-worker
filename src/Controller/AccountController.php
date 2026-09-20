<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Entity\UserSession;
use App\Form\AccountPasswordType;
use App\Repository\UserSessionRepository;
use App\Security\CmsPermission;
use App\Service\AccountTokenManager;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    #[Route('/account', name: 'app_account_home', methods: ['GET'])]
    public function home(): Response
    {
        $user = $this->getUser();
        if ($user instanceof User && ($user->isAdmin() || $this->isGranted(CmsPermission::ACCESS))) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        return $this->redirectToRoute('app_guild_portal_index');
    }

    #[Route('/account/security', name: 'app_account_security', methods: ['GET', 'POST'])]
    public function security(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        AuditLogger $audit,
        UserSessionRepository $sessions,
        AccountTokenManager $tokens,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }

        $form = $this->createForm(AccountPasswordType::class)->handleRequest($request);
        if ($form->isSubmitted()) {
            $currentPassword = (string) $form->get('currentPassword')->getData();
            $newPassword = (string) $form->get('newPassword')->getData();
            $currentPasswordValid = $passwordHasher->isPasswordValid($user, $currentPassword);

            if (!$currentPasswordValid) {
                $form->get('currentPassword')->addError(new FormError('Das bisherige Passwort ist nicht korrekt.'));
            } elseif ($passwordHasher->isPasswordValid($user, $newPassword)) {
                $form->get('newPassword')->addError(new FormError('Das neue Passwort muss sich vom bisherigen Passwort unterscheiden.'));
            }

            if ($form->isValid()) {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                $tokens->revoke($user, AccountToken::PURPOSE_PASSWORD_RESET);
                $revoked = $sessions->revokeAll($user, hash('sha256', $request->getSession()->getId()));
                $audit->record('security.password.changed', $user, $user->getId(), 'Eigenes Passwort geändert.', ['revokedSessions' => $revoked]);
                $entityManager->flush();
                $request->getSession()->migrate(true);
                $this->addFlash('success', 'Das Passwort wurde geändert. Ältere Sitzungen werden ungültig.');
                return $this->redirectToRoute('app_account_security');
            }
        }

        return $this->render('account/security.html.twig', [
            'form' => $form,
            'sessions' => $sessions->activeFor($user),
            'currentSessionHash' => hash('sha256', $request->getSession()->getId()),
        ]);
    }

    #[Route('/account/security/sessions/{id}/revoke', name: 'app_account_session_revoke', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function revokeSession(UserSession $session, Request $request, EntityManagerInterface $entityManager, AuditLogger $audit): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || $session->getUser() !== $user) { throw $this->createNotFoundException(); }
        if (!$this->isCsrfTokenValid('revoke-own-session-'.$session->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }

        $current = hash_equals($session->getSessionHash(), hash('sha256', $request->getSession()->getId()));
        $session->revoke();
        $audit->record('security.session.revoked', $user, $user->getId(), 'Eigene Sitzung widerrufen.', ['current' => $current]);
        $entityManager->flush();
        if ($current) {
            $request->getSession()->invalidate();
            return $this->redirectToRoute('app_login');
        }
        $this->addFlash('success', 'Die ausgewählte Sitzung wurde abgemeldet.');
        return $this->redirectToRoute('app_account_security');
    }
}
