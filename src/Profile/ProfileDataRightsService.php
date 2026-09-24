<?php

declare(strict_types=1);

namespace App\Profile;

use App\Entity\AccountToken;
use App\Entity\Profile\MemberProfile;
use App\Entity\Profile\ProfileDeletionRequest;
use App\Entity\User;
use App\Repository\Profile\MemberProfileRepository;
use App\Repository\Profile\ProfileDeletionRequestRepository;
use App\Repository\UserSessionRepository;
use App\Service\AccountTokenManager;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProfileDataRightsService
{
    public function __construct(
        private MemberProfileRepository $profiles,
        private ProfileDeletionRequestRepository $deletions,
        private UserSessionRepository $sessions,
        private AccountTokenManager $tokens,
        private EntityManagerInterface $entityManager,
    ) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $profile = $this->profiles->forUser($user);
        $deletion = $this->deletions->find($user->getId());

        return [
            'account' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'displayName' => $user->getDisplayName(),
                'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(DATE_ATOM),
                'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
            ],
            'profile' => $profile instanceof MemberProfile ? [
                'bio' => $profile->getBio(),
                'avatarMediaId' => $profile->getAvatar()?->getId(),
                'bannerMediaId' => $profile->getBanner()?->getId(),
                'visibility' => $profile->getVisibility(),
                'updatedAt' => $profile->getUpdatedAt()->format(DATE_ATOM),
            ] : null,
            'deletion' => $deletion instanceof ProfileDeletionRequest ? [
                'status' => $deletion->getStatus(),
                'requestedAt' => $deletion->getRequestedAt()->format(DATE_ATOM),
                'executeAfter' => $deletion->getExecuteAfter()->format(DATE_ATOM),
            ] : null,
        ];
    }

    public function requestDeletion(User $user, \DateTimeImmutable $now): ProfileDeletionRequest
    {
        $existing = $this->deletions->find($user->getId());
        if ($existing instanceof ProfileDeletionRequest) {
            $existing->request($now);
            return $existing;
        }

        $request = new ProfileDeletionRequest($user, $now);
        $this->entityManager->persist($request);
        return $request;
    }

    public function cancelDeletion(User $user, \DateTimeImmutable $now): void
    {
        $request = $this->deletions->find($user->getId());
        if (!$request instanceof ProfileDeletionRequest || $request->getStatus() !== ProfileDeletionRequest::STATUS_PENDING) {
            throw new \DomainException('No pending deletion request exists.');
        }
        $request->cancel($now);
    }

    public function execute(ProfileDeletionRequest $request, \DateTimeImmutable $now): void
    {
        if (!$request->isDue($now)) {
            throw new \DomainException('Deletion request is not due.');
        }

        $user = $request->getUser();
        $profile = $this->profiles->forUser($user);
        if ($profile instanceof MemberProfile) {
            $this->entityManager->remove($profile);
        }

        $this->sessions->revokeAll($user);
        $this->tokens->revoke($user, AccountToken::PURPOSE_EMAIL_VERIFICATION);
        $this->tokens->revoke($user, AccountToken::PURPOSE_PASSWORD_RESET);

        foreach ($user->getAccessRoles()->toArray() as $role) {
            $user->removeAccessRole($role);
        }
        $user->setPermissions([])
            ->setAdmin(false)
            ->setActive(false)
            ->disableTwoFactor()
            ->setDisplayName('Deleted member')
            ->setEmail(sprintf('deleted-%d-%s@example.invalid', $user->getId() ?? 0, bin2hex(random_bytes(8))))
            ->invalidateSessions();

        $request->complete($now);
    }
}
