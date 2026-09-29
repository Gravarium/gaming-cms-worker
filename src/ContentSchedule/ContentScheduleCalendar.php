<?php

declare(strict_types=1);

namespace App\ContentSchedule;

use App\Entity\ContentEntry;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

final readonly class ContentScheduleCalendar
{
    public const PAGE_SIZE = 50;
    public const MAX_PAGE = 10000;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{
     *     month: string,
     *     previousMonth: string,
     *     nextMonth: string,
     *     events: list<ContentScheduleEvent>,
     *     page: int,
     *     pageCount: int,
     *     pageSize: int,
     *     total: int,
     *     firstResult: int,
     *     lastResult: int
     * }
     */
    public function read(mixed $rawMonth, mixed $rawPage): array
    {
        $monthStart = $this->parseMonth($rawMonth);
        $monthEnd = $monthStart->modify('+1 month');
        $page = $this->parsePage($rawPage);
        $month = $monthStart->format('Y-m');

        $parameters = [
            'scheduled_status' => ContentEntry::STATUS_SCHEDULED,
            'published_status' => ContentEntry::STATUS_PUBLISHED,
            'publication_start' => $monthStart,
            'publication_end' => $monthEnd,
            'unpublication_start' => $monthStart,
            'unpublication_end' => $monthEnd,
        ];
        $types = [
            'scheduled_status' => ParameterType::STRING,
            'published_status' => ParameterType::STRING,
            'publication_start' => Types::DATETIME_IMMUTABLE,
            'publication_end' => Types::DATETIME_IMMUTABLE,
            'unpublication_start' => Types::DATETIME_IMMUTABLE,
            'unpublication_end' => Types::DATETIME_IMMUTABLE,
        ];

        $total = (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM (
                    SELECT id
                    FROM content_entry
                    WHERE status = :scheduled_status
                      AND scheduled_at >= :publication_start
                      AND scheduled_at < :publication_end
                    UNION ALL
                    SELECT id
                    FROM content_entry
                    WHERE status = :published_status
                      AND scheduled_unpublish_at >= :unpublication_start
                      AND scheduled_unpublish_at < :unpublication_end
                ) AS calendar_events
                SQL,
            $parameters,
            $types,
        );
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pageCount) {
            throw new \OutOfRangeException('Content schedule page is outside the available range.');
        }

        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT id, title, 'publication' AS kind, scheduled_at AS event_at
                FROM content_entry
                WHERE status = :scheduled_status
                  AND scheduled_at >= :publication_start
                  AND scheduled_at < :publication_end
                UNION ALL
                SELECT id, title, 'unpublication' AS kind, scheduled_unpublish_at AS event_at
                FROM content_entry
                WHERE status = :published_status
                  AND scheduled_unpublish_at >= :unpublication_start
                  AND scheduled_unpublish_at < :unpublication_end
                ORDER BY event_at ASC, id ASC, kind ASC
                LIMIT :result_limit OFFSET :result_offset
                SQL,
            $parameters + [
                'result_limit' => self::PAGE_SIZE,
                'result_offset' => ($page - 1) * self::PAGE_SIZE,
            ],
            $types + [
                'result_limit' => ParameterType::INTEGER,
                'result_offset' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            $rawId = $row['id'] ?? null;
            if (!is_int($rawId) && !(is_string($rawId) && ctype_digit($rawId))) {
                throw new \UnexpectedValueException('A content schedule event has an invalid identifier.');
            }

            $title = $row['title'] ?? null;
            $kind = $row['kind'] ?? null;
            $rawAt = $row['event_at'] ?? null;
            if (!is_string($title) || !in_array($kind, [ContentScheduleEvent::KIND_PUBLICATION, ContentScheduleEvent::KIND_UNPUBLICATION], true)) {
                throw new \UnexpectedValueException('A content schedule event has invalid fields.');
            }

            if ($rawAt instanceof DateTimeImmutable) {
                $at = $rawAt;
            } elseif ($rawAt instanceof DateTimeInterface) {
                $at = DateTimeImmutable::createFromInterface($rawAt);
            } elseif (is_string($rawAt)) {
                $at = new DateTimeImmutable($rawAt);
            } else {
                throw new \UnexpectedValueException('A content schedule event has an invalid date.');
            }

            $events[] = new ContentScheduleEvent((int) $rawId, $title, $kind, $at);
        }

        $offset = ($page - 1) * self::PAGE_SIZE;

        return [
            'month' => $month,
            'previousMonth' => $monthStart->modify('-1 month')->format('Y-m'),
            'nextMonth' => $monthEnd->format('Y-m'),
            'events' => $events,
            'page' => $page,
            'pageCount' => $pageCount,
            'pageSize' => self::PAGE_SIZE,
            'total' => $total,
            'firstResult' => $total === 0 ? 0 : $offset + 1,
            'lastResult' => min($offset + count($events), $total),
        ];
    }

    public function monthKey(mixed $value): string
    {
        return $this->parseMonth($value)->format('Y-m');
    }

    private function parseMonth(mixed $value): DateTimeImmutable
    {
        if ($value === null) {
            return (new DateTimeImmutable('first day of this month'))->setTime(0, 0);
        }

        if (!is_string($value) || preg_match('/^(?:20[0-9]{2}|2100)-(?:0[1-9]|1[0-2])$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Month must use the YYYY-MM format.');
        }

        $month = DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01');
        $errors = DateTimeImmutable::getLastErrors();
        if ($month === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $month->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('Month must be a valid calendar month.');
        }

        return $month;
    }

    private function parsePage(mixed $value): int
    {
        if ($value === null) {
            return 1;
        }

        if (is_int($value)) {
            $page = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,4}$/D', $value) === 1) {
            $page = (int) $value;
        } else {
            throw new \InvalidArgumentException('Page must be a positive decimal integer.');
        }

        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be a positive decimal integer.');
        }
        if ($page > self::MAX_PAGE) {
            throw new \OutOfRangeException('Page exceeds the supported limit.');
        }

        return $page;
    }
}
