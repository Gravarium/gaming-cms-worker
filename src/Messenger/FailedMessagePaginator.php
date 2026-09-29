<?php

declare(strict_types=1);

namespace App\Messenger;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class FailedMessagePaginator
{
    public const PAGE_SIZE = 25;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{
     *     total: int,
     *     messages: list<array{id: string, type: string, createdAt: \DateTimeImmutable, bytes: int}>,
     *     page: int,
     *     pageCount: int,
     *     first: int,
     *     last: int
     * }
     */
    public function read(mixed $pageInput = null): array
    {
        $requestedPage = self::parsePage($pageInput);
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
            ['failed'],
            [ParameterType::STRING],
        );
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($requestedPage, $pageCount);
        $offset = ($page - 1) * self::PAGE_SIZE;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, headers, created_at, LENGTH(body) AS body_size FROM messenger_messages WHERE queue_name = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            ['failed', self::PAGE_SIZE, $offset],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = [
                'id' => (string) $row['id'],
                'type' => FailedMessageOverview::sanitizedType((string) $row['headers']),
                'createdAt' => new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
                'bytes' => max(0, (int) $row['body_size']),
            ];
        }

        $first = $messages === [] ? 0 : $offset + 1;

        return [
            'total' => $total,
            'messages' => $messages,
            'page' => $page,
            'pageCount' => $pageCount,
            'first' => $first,
            'last' => $offset + count($messages),
        ];
    }

    private static function parsePage(mixed $pageInput): int
    {
        if ($pageInput === null) {
            return 1;
        }

        if (!is_string($pageInput) || preg_match('/\A[1-9][0-9]{0,8}\z/', $pageInput) !== 1) {
            throw new \InvalidArgumentException('The page must be a canonical positive integer.');
        }

        $page = (int) $pageInput;
        if ($page > intdiv(PHP_INT_MAX, self::PAGE_SIZE) + 1) {
            throw new \InvalidArgumentException('The page exceeds the supported range.');
        }

        return $page;
    }
}