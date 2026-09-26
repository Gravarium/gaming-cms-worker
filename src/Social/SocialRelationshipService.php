<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\Social\SocialBlock;
use App\Entity\Social\SocialPrivacySettings;
use App\Entity\Social\SocialRelationship;
use App\Entity\User;
use App\Repository\Social\SocialBlockRepository;
use App\Repository\Social\SocialPrivacySettingsRepository;
use App\Repository\Social\SocialRelationshipRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SocialRelationshipService
{
    public function __construct(
        private SocialModuleAvailability $availability,
        private SocialRateLimitPolicy $rateLimits,
        private SocialRelationshipRepository $relationships,
        private SocialBlockRepository $blocks,
        private SocialPrivacySettingsRepository $privacy,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function request(User $actor, User $target, string $type): SocialRelationship
    {
        $this->assertEnabled();
        if ($actor === $target || ($actor->getId() !== null && $actor->getId() === $target->getId()) || !$target->isActive()) {
            throw new \DomainException('The selected user cannot receive this request.');
        }
        if ($this->blocks->isBlockedEitherDirection($actor, $target)) {
            throw new \DomainException('A blocked relationship cannot be created.');
        }
        $targetSettings = $target->getId() === null ? new SocialPrivacySettings($target) : $this->privacy->forUser($target);
        if ($targetSettings?->getRelationshipPolicy() === SocialPrivacySettings::RELATIONSHIPS_NOBODY) {
            throw new \DomainException('This user does not accept relationship requests.');
        }
        $this->rateLimits->assertRelationshipWindow($this->relationships->countCreatedBySince($actor, new \DateTimeImmutable('-1 day')));

        $existing = $this->relationships->directed($actor, $target, $type);
        if ($existing instanceof SocialRelationship) {
            if ($existing->isPending() || $existing->isAccepted()) {
                return $existing;
            }
            $existing->reopen(new \DateTimeImmutable());

            return $existing;
        }
        $reverse = $this->relationships->directed($target, $actor, $type);
        if ($reverse?->isAccepted() === true) {
            return $reverse;
        }
        if ($reverse?->isPending() === true) {
            $reverse->accept(new \DateTimeImmutable());

            return $reverse;
        }

        $relationship = new SocialRelationship($actor, $target, $type);
        $this->entityManager->persist($relationship);

        return $relationship;
    }

    public function respond(User $actor, SocialRelationship $relationship, bool $accept): void
    {
        $this->assertEnabled();
        if (!$this->sameUser($actor, $relationship->getRecipient())) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Only the recipient can respond.');
        }
        if ($this->blocks->isBlockedEitherDirection($actor, $relationship->getRequester())) {
            throw new \DomainException('A blocked relationship cannot be accepted.');
        }
        if ($accept) {
            $relationship->accept(new \DateTimeImmutable());
        } else {
            $relationship->reject(new \DateTimeImmutable());
        }
    }

    public function cancel(User $actor, SocialRelationship $relationship): void
    {
        $this->assertEnabled();
        if (!$this->sameUser($actor, $relationship->getRequester())) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Only the requester can cancel.');
        }
        $relationship->cancel(new \DateTimeImmutable());
    }

    public function block(User $actor, User $target): SocialBlock
    {
        $this->assertEnabled();
        if ($actor === $target || ($actor->getId() !== null && $actor->getId() === $target->getId())) {
            throw new \InvalidArgumentException('A user cannot block themselves.');
        }
        $block = $this->blocks->directed($actor, $target);
        if ($block instanceof SocialBlock) {
            $block->reactivate(new \DateTimeImmutable());
        } else {
            $block = new SocialBlock($actor, $target);
            $this->entityManager->persist($block);
        }
        foreach ($this->relationships->forUser($actor) as $relationship) {
            if ($this->samePair($relationship, $actor, $target) && $relationship->isPending()) {
                if ($this->sameUser($actor, $relationship->getRequester())) {
                    $relationship->cancel(new \DateTimeImmutable());
                } else {
                    $relationship->reject(new \DateTimeImmutable());
                }
            }
        }

        return $block;
    }

    public function unblock(User $actor, User $target): void
    {
        $this->assertEnabled();
        $block = $this->blocks->directed($actor, $target);
        if ($block instanceof SocialBlock && $block->isActive()) {
            $block->lift(new \DateTimeImmutable());
        }
    }

    public function privacy(User $user): SocialPrivacySettings
    {
        $settings = $user->getId() === null ? null : $this->privacy->forUser($user);
        if ($settings instanceof SocialPrivacySettings) {
            return $settings;
        }
        $settings = new SocialPrivacySettings($user);
        $this->entityManager->persist($settings);

        return $settings;
    }

    private function assertEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
    }

    private function sameUser(User $first, User $second): bool
    {
        return $first === $second || ($first->getId() !== null && $first->getId() === $second->getId());
    }

    private function samePair(SocialRelationship $relationship, User $first, User $second): bool
    {
        return ($this->sameUser($relationship->getRequester(), $first) && $this->sameUser($relationship->getRecipient(), $second))
            || ($this->sameUser($relationship->getRequester(), $second) && $this->sameUser($relationship->getRecipient(), $first));
    }
}
