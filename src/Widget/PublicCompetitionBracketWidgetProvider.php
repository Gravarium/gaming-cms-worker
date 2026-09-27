<?php

declare(strict_types=1);

namespace App\Widget;

use App\Repository\PublicCompetitionBracketRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicCompetitionBracketWidgetProvider implements WidgetProvider
{
    public function __construct(
        private PublicCompetitionBracketRepository $competitions,
        private RequestStack $requests,
    ) {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                'gaming.competition-brackets',
                'Turnier-Brackets',
                'gaming',
                'widget/public_competition_brackets.html.twig',
                [],
                false,
            ),
        ];
    }

    /** @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== 'gaming.competition-brackets') {
            return [];
        }

        $request = $this->requests->getCurrentRequest();
        $cacheKey = '_cms_widget_data_'.$key;
        $cached = $request?->attributes->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $data = ['competitions' => $this->competitions->publicCompetitionsWithMatches()];
        $request?->attributes->set($cacheKey, $data);

        return $data;
    }
}
