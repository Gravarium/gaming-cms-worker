<?php

declare(strict_types=1);

namespace App\Support;

final readonly class CaseEvidence
{
    public function __construct(public int $ownerId, public string $type, public int $referenceId, public string $description, public string $checksum)
    {
        if ($ownerId < 1 || !in_array($type, ['media', 'download', 'audit_reference'], true) || $referenceId < 1) {
            throw new \InvalidArgumentException('Evidence must use a managed reference.');
        }
        if (trim($description) === '' || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new \InvalidArgumentException('Evidence description and SHA-256 checksum are required.');
        }
    }

    /** @return array{type: string, referenceId: int|null, description: string, checksum: string} */
    public function export(bool $authorized, bool $includeSensitive): array
    {
        if (!$authorized) throw new \DomainException('Evidence export denied.');

        return ['type' => $this->type, 'referenceId' => $includeSensitive ? $this->referenceId : null, 'description' => $this->description, 'checksum' => $this->checksum];
    }
}
