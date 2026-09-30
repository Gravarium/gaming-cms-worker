<?php

declare(strict_types=1);

namespace App\ProfileDirectory;

use App\Entity\Profile\MemberProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final readonly class PublicMemberDirectoryQuery
{
    public const PAGE_SIZE = 20;
    public const MAX_WIDGET_ITEMS = 12;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{members: list<array{id: int, displayName: string}>, page: int, pageCount: int, total: int}
     */
    public function page(int $requestedPage): array
    {
        $total = $this->countPublicMembers();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));

        if ($requestedPage < 1 || $requestedPage > $pageCount) {
            throw new \OutOfRangeException('The requested member directory page does not exist.');
        }

        return [
            'members' => $this->fetchPublicMembers(
                self::PAGE_SIZE,
                ($requestedPage - 1) * self::PAGE_SIZE,
            ),
            'page' => $requestedPage,
            'pageCount' => $pageCount,
            'total' => $total,
        ];
    }

    /**
     * @return list<array{id: int, displayName: string}>
     */
    public function publicMembers(int $limit = self::MAX_WIDGET_ITEMS): array
    {
        $boundedLimit = max(1, min(self::MAX_WIDGET_ITEMS, $limit));

        return $this->fetchPublicMembers($boundedLimit, 0);
    }

    private function countPublicMembers(): int
    {
        $sql = 'SELECT COUNT(*)
            FROM member_profile AS profile_row
            INNER JOIN cms_user AS account ON account.id = profile_row.user_id
            WHERE account.is_active = TRUE
                AND '.$this->publicDisplayNamePredicate();

        return (int) $this->connection
            ->executeQuery($sql, ['visibility' => MemberProfile::VISIBILITY_PUBLIC])
            ->fetchOne();
    }

    /**
     * @return list<array{id: int, displayName: string}>
     */
    private function fetchPublicMembers(int $limit, int $offset): array
    {
        $sql = 'SELECT account.id AS id, account.display_name AS display_name
            FROM member_profile AS profile_row
            INNER JOIN cms_user AS account ON account.id = profile_row.user_id
            WHERE account.is_active = TRUE
                AND '.$this->publicDisplayNamePredicate().'
            ORDER BY LOWER(account.display_name) ASC, account.id ASC
            LIMIT :limit OFFSET :offset';

        $rows = $this->connection->executeQuery(
            $sql,
            [
                'visibility' => MemberProfile::VISIBILITY_PUBLIC,
                'limit' => $limit,
                'offset' => $offset,
            ],
            [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'displayName' => (string) $row['display_name'],
            ],
            $rows,
        );
    }

    private function publicDisplayNamePredicate(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => "profile_row.visibility ->> 'display_name' = :visibility",
            $platform instanceof SQLitePlatform => "json_extract(profile_row.visibility, '$.display_name') = :visibility",
            $platform instanceof AbstractMySQLPlatform => "JSON_UNQUOTE(JSON_EXTRACT(profile_row.visibility, '$.display_name')) = :visibility",
            default => throw new \LogicException('The public member directory does not support this database platform.'),
        };
    }
}
