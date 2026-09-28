<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountToken;
use App\Entity\AccessRole;
use App\Entity\User;
use App\Entity\UserSession;
use App\Form\AdminUserType;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Security\PermissionDelegationPolicy;
use App\Service\AccountMailer;
use App\Service\AccountTokenManager;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('CMS_USERS_MANAGE')]
final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSessionRepository $sessions,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AccountTokenManager $tokens,
        private readonly AccountMailer $mailer,
        private readonly AuditLogger $audit,
        private readonly PermissionDelegationPolicy $delegation,
    ) {}

    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = mb_substr(trim($request->query->getString('q')), 0, 190);
        $state = $request->query->getString('state');
        $state = in_array($state, ['active', 'locked', 'unverified'], true) ? $state : null;
        return $this->render('admin/user/index.html.twig', [
            'users' => $this->users->searchAdmin($query, $state),
            'filters' => ['q' => $query, 'state' => $state],
            'canManageAdmins' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $actor = $this->currentActor();
        $canAssignAdmin = $actor->isAdmin();
        $form = $this->createForm(AdminUserType::class, $user, ['password_required' => true, 'can_assign_admin' => $canAssignAdmin])->handleRequest($request);

        if ($form->isSubmitted()) {
            if (!$canAssignAdmin) { $user->setAdmin(false); }
            $this->validateDelegation($actor, $user, $form);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $this->entityManager->persist($user);
            $this->audit->record('user.created', $user, null, 'Benutzerkonto angelegt.', ['email' => $user->getEmail(), 'roles' => $this->roleKeys($user)]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Das Benutzerkonto wurde erstellt.');
            return $this->redirectToRoute('app_admin_user_index');
        }

        $response = $this->render('admin/user/form.html.twig', ['form' => $form, 'heading' => 'Benutzer anlegen']);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/{id}/send-verification', name: 'app_admin_user_send_verification', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendVerification(User $user, Request $request): Response
    {
        $this->ensureCanManageTarget($user);
        if (!$this->isCsrfTokenValid('admin-send-verification-'.$user->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }

        [, $plainToken] = $this->tokens->issue($user, AccountToken::PURPOSE_EMAIL_VERIFICATION, new \DateInterval('P1D'));
        $this->audit->record('admin.email_verification.sent', $user, $user->getId(), 'E-Mail-Bestätigung durch Administration versendet.', ['alreadyVerified' => $user->isEmailVerified()]);
        $this->entityManager->flush();

        try {
            $this->mailer->sendVerification($user, $plainToken);
            $this->addFlash('success', 'Bestätigungsnachricht wurde über die Warteschlange eingeplant.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Die Bestätigungsnachricht konnte momentan nicht eingeplant werden.');
        }
        return $this->redirectToRoute('app_admin_user_index');
    }

    #[Route('/{id}/edit', name: 'app_admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request): Response
    {
        $this->ensureCanManageTarget($user);
        $before = [
            'email' => $user->getEmail(),
            'admin' => $user->isAdmin(),
            'permissions' => $user->getPermissions(),
            'active' => $user->isActive(),
            'roles' => $user->getAccessRoles()->toArray(),
            'lockedUntil' => $user->getLockedUntil(),
            'lockReason' => $user->getLockReason(),
        ];
        $actor = $this->currentActor();
        $canAssignAdmin = $actor->isAdmin();
        $editingSelf = $actor === $user;
        $form = $this->createForm(AdminUserType::class, $user, [
            'can_assign_admin' => $canAssignAdmin,
            'self_edit' => $editingSelf,
            'self_email' => $editingSelf ? $before['email'] : null,
        ])->handleRequest($request);
        $requestedSelfEmail = $editingSelf ? mb_strtolower(trim((string) $form->get('email')->getData())) : null;

        if ($form->isSubmitted()) {
            if (!$canAssignAdmin) { $user->setAdmin($before['admin']); }
            if ($editingSelf) {
                if ($requestedSelfEmail !== $before['email']) {
                    $currentPassword = (string) $form->get('currentPassword')->getData();
                    if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                        $form->get('currentPassword')->addError(new FormError('Bestätige die Änderung deiner E-Mail-Adresse mit deinem aktuellen Passwort.'));
                    } else {
                        $existing = $this->users->findOneBy(['email' => $requestedSelfEmail]);
                        if ($existing instanceof User && $existing !== $user) {
                            $form->get('email')->addError(new FormError('Diese E-Mail-Adresse wird bereits verwendet.'));
                        }
                    }
                }
                $selfPassword = $form->get('plainPassword')->getData();
                if (is_string($selfPassword) && $selfPassword !== '') {
                    $form->get('plainPassword')->get('first')->addError(new FormError('Ändere dein eigenes Passwort über die Kontosicherheit mit Bestätigung des bisherigen Passworts.'));
                }
                if ($user->isActive() !== $before['active'] || $user->isLocked()) { $form->get('active')->addError(new FormError('Du kannst dein eigenes Konto nicht sperren.')); }
                if ($user->getPermissions() !== $before['permissions'] || $this->roleKeys($user) !== $this->roleKeysFromArray($before['roles'])) {
                    $form->get('permissions')->addError(new FormError('Du kannst deine eigenen Rechte oder Rollen nicht ändern.'));
                }
                $user->setActive($before['active'])->setAdmin($before['admin'])->setPermissions($before['permissions'])
                    ->setLockedUntil($before['lockedUntil'])->setLockReason($before['lockReason']);
                foreach ($user->getAccessRoles()->toArray() as $role) { $user->removeAccessRole($role); }
                foreach ($before['roles'] as $role) { $user->addAccessRole($role); }
            }

            $this->validateDelegation($actor, $user, $form);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($editingSelf && $requestedSelfEmail !== null && $requestedSelfEmail !== $before['email']) {
                $user->setEmail($requestedSelfEmail);
            }
            $plainPassword = $form->get('plainPassword')->getData();
            $emailChanged = $before['email'] !== $user->getEmail();
            $securityChanged = $emailChanged
                || $before['active'] !== $user->isActive()
                || $before['admin'] !== $user->isAdmin()
                || $before['permissions'] !== $user->getPermissions()
                || $this->roleKeysFromArray($before['roles']) !== $this->roleKeys($user)
                || $before['lockedUntil'] != $user->getLockedUntil();

            if ($emailChanged) {
                $this->tokens->revoke($user, AccountToken::PURPOSE_EMAIL_VERIFICATION);
                $this->tokens->revoke($user, AccountToken::PURPOSE_PASSWORD_RESET);
            }
            if (is_string($plainPassword) && $plainPassword !== '') {
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
                $this->tokens->revoke($user, AccountToken::PURPOSE_PASSWORD_RESET);
                $securityChanged = true;
            }
            if ($securityChanged) {
                $user->invalidateSessions();
                if ($editingSelf) {
                    $current = $this->sessions->findBySessionId($request->getSession()->getId());
                    $this->sessions->revokeAll($user, $current?->getSessionHash());
                    $current?->syncSecurityVersion();
                } else {
                    $this->sessions->revokeAll($user);
                }
            }
            $this->audit->record('user.updated', $user, $user->getId(), 'Benutzerkonto und Zugriffsrechte aktualisiert.', [
                'active' => $user->isActive(),
                'admin' => $user->isAdmin(),
                'permissions' => $user->getPermissions(),
                'roles' => $this->roleKeys($user),
                'lockedUntil' => $user->getLockedUntil()?->format(DATE_ATOM),
                'emailChanged' => $emailChanged,
                'sessionsInvalidated' => $securityChanged,
            ]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Das Benutzerkonto wurde aktualisiert.');
            return $this->redirectToRoute('app_admin_user_index');
        }

        $response = $this->render('admin/user/form.html.twig', ['form' => $form, 'heading' => 'Benutzer bearbeiten', 'edited_user' => $user]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/{id}/sessions', name: 'app_admin_user_sessions', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sessions(User $user): Response
    {
        $this->ensureCanManageTarget($user);
        return $this->render('admin/user/sessions.html.twig', ['managed_user' => $user, 'sessions' => $this->sessions->activeFor($user)]);
    }

    #[Route('/{id}/sessions/{sessionId}/revoke', name: 'app_admin_user_session_revoke', requirements: ['id' => '\d+', 'sessionId' => '\d+'], methods: ['POST'])]
    public function revokeSession(User $user, int $sessionId, Request $request): Response
    {
        $this->ensureCanManageTarget($user);
        $session = $this->sessions->find($sessionId);
        if (!$session instanceof UserSession || $session->getUser() !== $user) { throw $this->createNotFoundException(); }
        if (!$this->isCsrfTokenValid('revoke-user-session-'.$sessionId, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $session->revoke();
        $this->audit->record('user.session.revoked', $user, $user->getId(), 'Benutzersitzung widerrufen.', ['sessionId' => $sessionId]);
        $this->entityManager->flush();
        $this->addFlash('success', 'Die Sitzung wurde widerrufen.');
        return $this->redirectToRoute('app_admin_user_sessions', ['id' => $user->getId()]);
    }

    private function ensureCanManageTarget(User $user): void
    {
        $actor = $this->currentActor();
        if (!$this->delegation->canManageUser($actor, $user)) {
            throw $this->createAccessDeniedException('Du darfst kein stärker privilegiertes Benutzerkonto verwalten.');
        }
    }

    /** @param FormInterface<mixed> $form */
    private function validateDelegation(User $actor, User $target, FormInterface $form): void
    {
        if (!$this->delegation->canDelegatePermissions($actor, $target->getPermissions())) {
            $form->get('permissions')->addError(new FormError('Du kannst nur Berechtigungen vergeben, die du selbst besitzt.'));
        }

        if (!$this->delegation->canDelegateRoles($actor, $target->getAccessRoles())) {
            $form->get('accessRoles')->addError(new FormError('Du kannst nur Rollen vergeben, deren Berechtigungen du selbst besitzt.'));
        }
    }

    private function currentActor(): User
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $actor;
    }

    /** @return list<string> */
    private function roleKeys(User $user): array { return $this->roleKeysFromArray($user->getAccessRoles()->toArray()); }
    /** @param array<int, AccessRole> $roles
     * @return list<string>
     */
    private function roleKeysFromArray(array $roles): array
    {
        $keys = array_map(static fn (AccessRole $role): string => $role->getKey(), $roles);
        sort($keys);
        return $keys;
    }
}
