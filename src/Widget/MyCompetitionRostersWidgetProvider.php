<?php

declare(strict_types=1);

namespace App\Widget;

use App\CompetitionRoster\CompetitionRosterQuery;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class MyCompetitionRostersWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.competition-rosters';
    private const CACHE_KEY = '_cms_widget_data_gaming.competition-rosters';
    public const PERSONALIZED_ATTRIBUTE = '_cms_personalized_competition_rosters';

    public function __construct(
        private CompetitionRosterQuery $rosters,
        private Security $security,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Meine Competition-Teams',
            'gaming',
            'widget/my_rosters.html.twig',
            [],
            false,
        )];
    }

    /**
     * @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return ['authenticated' => false, 'teams' => []];
        }

        $request = $this->requests->getCurrentRequest();
        $request?->attributes->set(self::PERSONALIZED_ATTRIBUTE, true);

        /** @var list<array<string, mixed>>|null $teams */
        $teams = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($teams)) {
            $teams = $this->rosters->forCaptain($user, 6);
            $request?->attributes->set(self::CACHE_KEY, $teams);
        }

        return ['authenticated' => true, 'teams' => $teams];
    }
}
