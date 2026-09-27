<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\User;
use App\Social\SocialModuleAvailability;
use App\Widget\Social\UnreadConversationsQuery;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SocialUnreadConversationsWidgetProvider implements WidgetProvider
{
    public const KEY = 'social.unread-conversations';
    public const PERSONALIZED_CACHE_ATTRIBUTE = '_cms_personalized_social_unread_conversations';

    private const REQUEST_CACHE_PREFIX = '_cms_social_unread_summary_user_';

    public function __construct(
        private UnreadConversationsQuery $unread,
        private SocialModuleAvailability $availability,
        private Security $security,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Ungelesene Social-Nachrichten',
            'social',
            'widget/unread_conversations.html.twig',
        )];
    }

    /**
     * @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY || !$this->availability->enabled()) {
            return [];
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return ['authenticated' => false];
        }

        $request = $this->requests->getMainRequest();
        if (!$request instanceof Request) {
            return ['authenticated' => false];
        }

        $request->attributes->set(self::PERSONALIZED_CACHE_ATTRIBUTE, true);

        $userKey = (string) ($user->getId() ?? spl_object_id($user));
        $cacheKey = self::REQUEST_CACHE_PREFIX.$userKey;

        /** @var array{unreadConversations: int, unreadMessages: int}|null $summary */
        $summary = $request->attributes->get($cacheKey);
        if (!is_array($summary)) {
            $summary = $this->unread->forUser($user);
            $request->attributes->set($cacheKey, $summary);
        }

        return ['authenticated' => true] + $summary;
    }
}
