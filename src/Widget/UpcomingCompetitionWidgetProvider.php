<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\Competition\Competition;
use App\Repository\Competition\CompetitionRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final class UpcomingCompetitionWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.upcoming_competitions';
    private const CACHE_KEY = '_cms_widget_data_gaming.upcoming_competitions';
    private const MAX_ITEMS = 12;

    public function __construct(
        private readonly CompetitionRepository $competitions,
        private readonly RequestStack $requests,
    ) {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Nächste Competitions',
                'gaming',
                'widget/upcoming_competitions.html.twig',
            ),
        ];
    }

    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $requestedCount = $config['count'] ?? 6;
        $count = is_int($requestedCount) ? max(1, min(self::MAX_ITEMS, $requestedCount)) : 6;

        $request = $this->requests->getCurrentRequest();
        $competitions = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($competitions)) {
            $competitions = $this->findUpcoming();
            $request?->attributes->set(self::CACHE_KEY, $competitions);
        }

        /** @var list<Competition> $competitions */
        return ['items' => array_slice($competitions, 0, $count)];
    }

    /** @return list<Competition> */
    private function findUpcoming(): array
    {
        /** @var list<Competition> $competitions */
        $competitions = $this->competitions->createQueryBuilder('competition')
            ->addSelect('game')
            ->join('competition.game', 'game')
            ->andWhere('competition.visibility = :visibility')
            ->andWhere('competition.status = :status')
            ->andWhere('competition.startsAt >= :now')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('visibility', Competition::VISIBILITY_PUBLIC)
            ->setParameter('status', Competition::STATUS_OPEN)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('enabled', true)
            ->orderBy('competition.startsAt', 'ASC')
            ->addOrderBy('competition.id', 'ASC')
            ->setMaxResults(self::MAX_ITEMS)
            ->getQuery()
            ->getResult();

        return $competitions;
    }
}
