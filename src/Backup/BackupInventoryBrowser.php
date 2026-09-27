<?php

declare(strict_types=1);

namespace App\Backup;

use App\Entity\BackupVerificationStatus;

final class BackupInventoryBrowser
{
    public const PAGE_SIZE = 25;

    private const VERIFICATION_FILTERS = ['all', 'successful', 'failed', 'unchecked'];

    /**
     * @return array{query: string, verification_filter: 'all'|'successful'|'failed'|'unchecked', page: int}
     */
    public function normalizeRequest(mixed $query, mixed $verificationFilter, mixed $page): array
    {
        if (!is_string($query) || strlen($query) > 80 || preg_match('/\A[A-Za-z0-9_-]{0,80}\z/', $query) !== 1) {
            throw new \InvalidArgumentException('Invalid backup search query.');
        }

        if (!is_string($verificationFilter)) {
            throw new \InvalidArgumentException('Invalid backup verification filter.');
        }
        $normalizedFilter = match ($verificationFilter) {
            'all' => 'all',
            'successful' => 'successful',
            'failed' => 'failed',
            'unchecked' => 'unchecked',
            default => throw new \InvalidArgumentException('Invalid backup verification filter.'),
        };

        $pageValue = is_int($page) ? (string) $page : $page;
        if (!is_string($pageValue) || preg_match('/\A[1-9][0-9]{0,5}\z/', $pageValue) !== 1) {
            throw new \InvalidArgumentException('Invalid backup inventory page.');
        }

        return [
            'query' => $query,
            'verification_filter' => $normalizedFilter,
            'page' => (int) $pageValue,
        ];
    }

    /**
     * @param list<array{id: string, createdAt: \DateTimeImmutable, revision: string, bytes: int, objectStorage: string}> $backups
     * @param array<string, BackupVerificationStatus> $verificationStatuses
     *
     * @return array{
     *     backups: list<array{id: string, createdAt: \DateTimeImmutable, revision: string, bytes: int, objectStorage: string}>,
     *     latest_backup: array{id: string, createdAt: \DateTimeImmutable, revision: string, bytes: int, objectStorage: string}|null,
     *     total_backups: int,
     *     total_pages: int,
     *     current_page: int,
     *     page_size: int,
     *     first_result: int,
     *     last_result: int
     * }
     */
    public function paginate(
        array $backups,
        array $verificationStatuses,
        string $query,
        string $verificationFilter,
        int $page,
    ): array {
        $criteria = $this->normalizeRequest($query, $verificationFilter, $page);
        $orderedBackups = $backups;
        usort($orderedBackups, self::compareNewestFirst(...));

        $latestBackup = $orderedBackups[0] ?? null;
        $needle = strtolower($criteria['query']);
        $matchingBackups = [];

        foreach ($orderedBackups as $backup) {
            if (
                $needle !== ''
                && !str_contains(strtolower($backup['id']), $needle)
                && !str_contains(strtolower($backup['revision']), $needle)
            ) {
                continue;
            }

            $status = $verificationStatuses[$backup['id']] ?? null;
            $matchesStatus = match ($criteria['verification_filter']) {
                'all' => true,
                'successful' => $status instanceof BackupVerificationStatus && $status->isSuccessful(),
                'failed' => $status instanceof BackupVerificationStatus && !$status->isSuccessful(),
                'unchecked' => !($status instanceof BackupVerificationStatus),
            };
            if (!$matchesStatus) {
                continue;
            }

            $matchingBackups[] = $backup;
        }

        $totalBackups = count($matchingBackups);
        $totalPages = max(1, (int) ceil($totalBackups / self::PAGE_SIZE));
        if ($criteria['page'] > $totalPages) {
            throw new \OutOfRangeException('Backup inventory page does not exist.');
        }

        $offset = ($criteria['page'] - 1) * self::PAGE_SIZE;

        return [
            'backups' => array_slice($matchingBackups, $offset, self::PAGE_SIZE),
            'latest_backup' => $latestBackup,
            'total_backups' => $totalBackups,
            'total_pages' => $totalPages,
            'current_page' => $criteria['page'],
            'page_size' => self::PAGE_SIZE,
            'first_result' => $totalBackups === 0 ? 0 : $offset + 1,
            'last_result' => min($offset + self::PAGE_SIZE, $totalBackups),
        ];
    }

    /**
     * @param array{id: string, createdAt: \DateTimeImmutable, revision: string, bytes: int, objectStorage: string} $left
     * @param array{id: string, createdAt: \DateTimeImmutable, revision: string, bytes: int, objectStorage: string} $right
     */
    private static function compareNewestFirst(array $left, array $right): int
    {
        $createdAt = $right['createdAt'] <=> $left['createdAt'];

        return $createdAt !== 0 ? $createdAt : strcmp($right['id'], $left['id']);
    }
}
