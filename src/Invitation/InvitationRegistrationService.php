<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Entity\AccountToken;
use App\Entity\Invitation\MemberInvitation;
use App\Entity\User;
use App\Repository\Invitation\MemberInvitationRepository;
use App\Repository\UserRepository;
use App\Service\AccountMailer;
use App\Service\AccountTokenManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class InvitationRegistrationService
{
    public function __construct(
        private MemberInvitationRepository $invitations,
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private AccountTokenManager $tokens,
        private AccountMailer $mailer,
        private EntityManagerInterface $entityManager,
    ) {}

    public function resolve(string $rawToken, \DateTimeImmutable $now): ?MemberInvitation
    {
        $invitation = $this->invitations->byRawToken($rawToken);
        return $invitation instanceof MemberInvitation && $invitation->isUsable($now) ? $invitation : null;
    }

    public function register(
        string $rawToken,
        string $displayName,
        string $plainPassword,
        \DateTimeImmutable $now,
    ): InvitationRegistrationResult {
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 80 || $plainPassword === '') {
            throw new \InvalidArgumentException('Invalid invitation registration data.');
        }

        $user = null;
        $verificationToken = null;

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $rawToken,
            $displayName,
            $plainPassword,
            $now,
            &$user,
            &$verificationToken,
        ): void {
            $invitation = $this->invitations->byRawToken($rawToken);
            if (!$invitation instanceof MemberInvitation) {
                throw new \DomainException('Invitation is invalid.');
            }
            $entityManager->lock($invitation, LockMode::PESSIMISTIC_WRITE);
            if (!$invitation->isUsable($now)) {
                throw new \DomainException('Invitation is expired, revoked, used or no longer privilege-safe.');
            }
            if ($this->users->findOneBy(['email' => $invitation->getEmail()]) instanceof User) {
                throw new \DomainException('An account already exists for this invitation email.');
            }

            $newUser = (new User())
                ->setEmail($invitation->getEmail())
                ->setDisplayName($displayName);
            $newUser->setPassword($this->passwordHasher->hashPassword($newUser, $plainPassword));

            $role = $invitation->getAccessRole();
            if ($role !== null) {
                $newUser->addAccessRole($role);
            }

            $entityManager->persist($newUser);
            $entityManager->flush();
            $invitation->accept($newUser, $now);
            [, $token] = $this->tokens->issue($newUser, AccountToken::PURPOSE_EMAIL_VERIFICATION, new \DateInterval('P1D'));
            $entityManager->flush();

            $user = $newUser;
            $verificationToken = $token;
        });

        if (!$user instanceof User || !is_string($verificationToken)) {
            throw new \LogicException('Invitation registration did not produce an account.');
        }

        $mailQueued = true;
        try {
            $this->mailer->sendVerification($user, $verificationToken);
        } catch (\Throwable) {
            $mailQueued = false;
        }

        return new InvitationRegistrationResult($user, $mailQueued);
    }
}
