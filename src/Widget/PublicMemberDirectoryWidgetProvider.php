<?php

declare(strict_types=1);

namespace App\Widget;

use App\ProfileDirectory\PublicMemberDirectoryQuery;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicMemberDirectoryWidgetProvider implements WidgetProvider
{
    public const KEY = 'users.public-members';

    private const CACHE_KEY = '_cms_widget_data_users.public-members';

    public function __construct(
        private PublicMemberDirectoryQuery $directory,
        private RequestStack $requests,
    ) {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Öffentliche Mitglieder',
                'users',
                'widget/member_directory.html.twig',
            ),
        ];
    }

    /**
     * @param array<string, string|int|bool> $config
     *
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $requestedCount = $config['count'] ?? 6;
        $count = is_int($requestedCount)
            ? max(1, min(PublicMemberDirectoryQuery::MAX_WIDGET_ITEMS, $requestedCount))
            : 6;

        $request = $this->requests->getCurrentRequest();

        /** @var list<array{id: int, displayName: string}>|null $members */
        $members = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($members)) {
            $members = $this->directory->publicMembers(PublicMemberDirectoryQuery::MAX_WIDGET_ITEMS);
            $request?->attributes->set(self::CACHE_KEY, $members);
        }

        return ['members' => array_slice($members, 0, $count)];
    }
}
