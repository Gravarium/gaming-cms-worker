<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\Profile\MemberProfile;
use App\Entity\User;
use App\Profile\ProfileVisibilityPolicy;
use App\Repository\Profile\MemberProfileRepository;

final readonly class SocialConversationRecipientQuery
{
    public function __construct(
        private MemberProfileRepository $profiles,
        private ProfileVisibilityPolicy $visibility,
    ) {
    }

    /**
     * Return only IDs and labels whose display-name visibility allows this viewer.
     *
     * @return array<string, int>
     */
    public function choicesFor(User $viewer): array
    {
        /** @var list<array{0: MemberProfile, 1: User}> $rows */
        $rows = $this->profiles->createQueryBuilder('profile')
            ->select('profile', 'recipientUser')
            ->innerJoin('profile.user', 'recipientUser')
            ->andWhere('recipientUser.isActive = true')
            ->andWhere('(recipientUser.lockedUntil IS NULL OR recipientUser.lockedUntil <= :now)')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('LOWER(recipientUser.displayName)', 'ASC')
            ->addOrderBy('recipientUser.id', 'ASC')
            ->getQuery()
            ->getResult();

        $choices = [];
        foreach ($rows as [$profile, $recipientUser]) {
            $id = $recipientUser->getId();
            if (
                $id === null
                || $id === $viewer->getId()
                || !$this->visibility->canView($profile, MemberProfile::FIELD_DISPLAY_NAME, $viewer)
            ) {
                continue;
            }

            $choices[sprintf('%s (#%d)', $recipientUser->getDisplayName(), $id)] = $id;
        }

        return $choices;
    }
}
