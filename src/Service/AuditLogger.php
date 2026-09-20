<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {}

    /** @param array<string, mixed> $context */
    public function record(string $action, object|string $subject, ?int $subjectId, string $summary, array $context = []): void
    {
        $actor = $this->security->getUser();
        $type = is_object($subject) ? $subject::class : $subject;
        $log = (new AuditLog())->setActor($actor instanceof User ? $actor : null)->setAction($action)->setSubjectType($type)->setSubjectId($subjectId)->setSummary($summary)->setContext($this->sanitize($context))->setIpAddress($this->requestStack->getCurrentRequest()?->getClientIp());
        $this->entityManager->persist($log);
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        foreach (['password', 'plainPassword', 'token', 'secret', 'webhook'] as $key) { unset($context[$key]); }
        return $context;
    }
}
