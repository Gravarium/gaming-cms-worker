<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\Social\SocialMessage;
use App\Entity\Social\SocialModerationDecision;
use App\Entity\Social\SocialReport;
use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SocialModerationService
{
    public function __construct(
        private SocialModuleAvailability $availability,
        private SocialAccessPolicy $access,
        private EntityManagerInterface $entityManager,
        private AuditLogger $audit,
    ) {
    }

    public function report(User $reporter, SocialMessage $message, string $reason, ?string $details = null): SocialReport
    {
        $this->assertEnabled();
        if (!$this->access->canAccessMessage($reporter, $message)) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Message access is required to report it.');
        }
        if ($message->getAuthor() === $reporter || ($message->getAuthor()->getId() !== null && $message->getAuthor()->getId() === $reporter->getId())) {
            throw new \DomainException('A user cannot report their own message.');
        }

        $report = new SocialReport($message, $reporter, $reason, $details);
        $this->entityManager->persist($report);

        return $report;
    }

    public function decide(User $moderator, SocialReport $report, bool $upheld, string $reason): SocialModerationDecision
    {
        $this->assertEnabled();
        $this->assertModerator($moderator);
        if ($report->getStatus() === SocialReport::STATUS_OPEN) {
            $report->startReview();
        }
        $now = new \DateTimeImmutable();
        $report->decide($upheld, $moderator, $reason, $now);
        if ($upheld && !$report->getMessage()->isDeleted()) {
            $report->getMessage()->softDelete($moderator, 'Moderation decision: '.$reason, $now);
        }
        $messageId = $report->getMessage()->getId();
        if ($messageId === null) {
            throw new \LogicException('A reported message must be persisted before moderation.');
        }
        $decision = new SocialModerationDecision('message', $messageId, $moderator, $upheld ? SocialModerationDecision::ACTION_DELETE : SocialModerationDecision::ACTION_RESTORE, $reason);
        $this->entityManager->persist($decision);
        $this->audit->record('social.moderation_decision', SocialModerationDecision::class, $decision->getId(), 'Social report decided.', [
            'reportId' => $report->getId(),
            'messageId' => $report->getMessage()->getId(),
            'upheld' => $upheld,
        ]);

        return $decision;
    }

    private function assertModerator(User $user): void
    {
        if (!$user->isActive() || !$user->isAdmin()) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Social moderation requires an administrator.');
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
    }
}
