<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Entity\AccessRole;
use App\Entity\Invitation\MemberInvitation;
use App\Entity\User;
use App\Repository\Invitation\MemberInvitationRepository;
use App\Security\CmsPermission;
use App\Security\PermissionDelegationPolicy;
use Doctrine\ORM\EntityManagerInterface;

final readonly class InvitationService
{
    public function __construct(
        private MemberInvitationRepository $invitations,
        private PermissionDelegationPolicy $delegation,
        private EntityManagerInterface $entityManager,
    ) {}

    public function issue(
        User $actor,
        string $email,
        ?AccessRole $role,
        int $ttlHours,
        \DateTimeImmutable $now,
    ): InvitationIssueResult {
        if (!$actor->isAdmin() && !$actor->hasPermission(CmsPermission::USERS)) {
            throw new \DomainException('Invitation issuer lacks user-management permission.');
        }
        if ($role !== null && (!$role->isActive() || !$this->delegation->canDelegateRoles($actor, [$role]))) {
            throw new \DomainException('Invitation role exceeds the issuer permission ceiling.');
        }
        if ($ttlHours < 1 || $ttlHours > 168) {
            throw new \InvalidArgumentException('Invitation lifetime must be between 1 and 168 hours.');
        }

        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180) {
            throw new \InvalidArgumentException('Invalid invitation email.');
        }
        if ($this->invitations->countPendingByCreatorSince($actor, $now->modify('-1 hour')) >= 10
            || $this->invitations->countPendingByCreatorSince($actor, $now->modify('-1 day')) >= 50
            || $this->invitations->countActiveForEmail($email, $now) >= 2
        ) {
            throw new \DomainException('Invitation abuse limit reached.');
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $snapshot = $role?->getPermissions() ?? [];
        $invitation = new MemberInvitation(
            $actor,
            $email,
            hash('sha256', $raw),
            $role,
            $snapshot,
            $now->modify('+'.$ttlHours.' hours'),
            $now,
        );
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        return new InvitationIssueResult($invitation, $raw);
    }

    public function revoke(MemberInvitation $invitation, User $actor, \DateTimeImmutable $now): void
    {
        if ($invitation->getCreatedBy() !== $actor && !$actor->isAdmin()) {
            throw new \DomainException('Only the issuer or an administrator may revoke this invitation.');
        }
        $invitation->revoke($now);
        $this->entityManager->flush();
    }
}
