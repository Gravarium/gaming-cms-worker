<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\User;
use App\Repository\GuildEventSignupPortalRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class MyGuildEventSignupsWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.my-event-signups';
    public const PERSONALIZED_DATA_KEY = '_cms_widget_data_gaming.my-event-signups';
    public const PRIVATE_RESPONSE_KEY = '_cms_widget_private_gaming.my-event-signups';

    public function __construct(
        private GuildEventSignupPortalRepository $signups,
        private RequestStack $requests,
        private Security $security,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Meine kommenden Gildentermine',
            'gaming',
            'widget/my_event_signups.html.twig',
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
            return ['signups' => []];
        }

        $request = $this->requests->getCurrentRequest();
        $request?->attributes->set(self::PRIVATE_RESPONSE_KEY, true);

        /** @var list<array{id:int, guildName:string, characterName:string, eventTitle:string, startsAt:\DateTimeImmutable, response:string, role:string}>|null $signups */
        $signups = $request?->attributes->get(self::PERSONALIZED_DATA_KEY);
        if (!is_array($signups)) {
            $signups = $this->signups->upcomingForUser($user, new \DateTimeImmutable());
            $request?->attributes->set(self::PERSONALIZED_DATA_KEY, $signups);
        }

        return ['signups' => array_slice($signups, 0, GuildEventSignupPortalRepository::WIDGET_LIMIT)];
    }
}
