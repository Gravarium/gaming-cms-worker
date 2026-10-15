<?php

declare(strict_types=1);

namespace App\ContentRelease;

use App\Entity\ContentRelease;
use App\Repository\ContentReleaseRepository;

final readonly class AdminContentReleaseCalendar
{
    public const EVENT_LIMIT = 100;

    public function __construct(private ContentReleaseRepository $releases)
    {
    }

    /**
     * @return array{month: string, monthLabel: string, previousMonth: string, nextMonth: string, days: list<array{date: string, label: string, events: list<array{id: int, name: string, status: string, time: string}>}>, hasMore: bool}
     */
    public function read(mixed $monthInput = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $month = self::parseMonth($monthInput, $now);
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01', new \DateTimeZone('UTC'));
        if (!$start instanceof \DateTimeImmutable) {
            throw new \LogicException('The validated calendar month could not be created.');
        }
        $end = $start->modify('+1 month');

        $query = $this->releases->createQueryBuilder('release');
        $query
            ->andWhere('(release.status = :scheduledStatus AND release.scheduledAt >= :start AND release.scheduledAt < :end) OR (release.status = :publishedStatus AND release.publishedAt >= :start AND release.publishedAt < :end)')
            ->setParameter('scheduledStatus', ContentRelease::STATUS_SCHEDULED)
            ->setParameter('publishedStatus', ContentRelease::STATUS_PUBLISHED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('release.scheduledAt', 'ASC')
            ->addOrderBy('release.publishedAt', 'ASC')
            ->addOrderBy('release.id', 'ASC')
            ->setMaxResults(self::EVENT_LIMIT + 1);

        /** @var list<ContentRelease> $releases */
        $releases = $query->getQuery()->getResult();
        $hasMore = count($releases) > self::EVENT_LIMIT;
        $releases = array_slice($releases, 0, self::EVENT_LIMIT);
        $grouped = [];
        foreach ($releases as $release) {
            $at = $release->getStatus() === ContentRelease::STATUS_SCHEDULED
                ? $release->getScheduledAt()
                : $release->getPublishedAt();
            if ($at === null || $release->getId() === null) {
                continue;
            }
            $date = $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
            $grouped[$date][] = [
                'id' => $release->getId(),
                'name' => $release->getName(),
                'status' => $release->getStatus(),
                'time' => $at->setTimezone(new \DateTimeZone('UTC'))->format('H:i'),
            ];
        }

        $days = [];
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'label' => $day->format('D, d.m.'),
                'events' => $grouped[$date] ?? [],
            ];
        }

        return [
            'month' => $month,
            'monthLabel' => $start->format('F Y'),
            'previousMonth' => $start->modify('-1 month')->format('Y-m'),
            'nextMonth' => $start->modify('+1 month')->format('Y-m'),
            'days' => $days,
            'hasMore' => $hasMore,
        ];
    }

    private static function parseMonth(mixed $monthInput, \DateTimeImmutable $now): string
    {
        if ($monthInput === null) {
            return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m');
        }
        if (!is_string($monthInput) || preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/', $monthInput) !== 1) {
            throw new \InvalidArgumentException('The calendar month must use the YYYY-MM format.');
        }
        $year = (int) substr($monthInput, 0, 4);
        if ($year < 1 || $year > 9998) {
            throw new \InvalidArgumentException('The calendar month is outside the supported range.');
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m', $monthInput, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof \DateTimeImmutable || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m') !== $monthInput) {
            throw new \InvalidArgumentException('The calendar month is invalid.');
        }

        return $monthInput;
    }
}
