<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class MyCompetitionsWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.my-competition-entries';
    private const CACHE_KEY = '_cms_widget_data_gaming.my-competition-entries';
    public const PERSONALIZED_ATTRIBUTE = '_cms_personalized_competition_widget';

    public function __construct(
        private CompetitionParticipationQuery $participation,
        private Security $security,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Meine Competition-Anmeldungen',
            'gaming',
            'widget/my_competitions.html.twig',
            [],
            false,
        )];
    }

    /** @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return ['authenticated' => false, 'entries' => [], 'activeCount' => 0];
        }

        $request = $this->requests->getCurrentRequest();
        $request?->attributes->set(self::PERSONALIZED_ATTRIBUTE, true);

        /** @var list<CompetitionParticipant>|null $entries */
        $entries = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($entries)) {
            $entries = $this->participation->forCaptain($user, 6);
            $request?->attributes->set(self::CACHE_KEY, $entries);
        }

        return [
            'authenticated' => true,
            'entries' => $entries,
            'activeCount' => count(array_filter(
                $entries,
                static fn (CompetitionParticipant $entry): bool => $entry->isActive(),
            )),
        ];
    }
}
