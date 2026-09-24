<?php

declare(strict_types=1);

namespace App\Gaming\Event;

final class EventVisibilityPolicy
{
    public const PUBLIC = 'public';
    public const MEMBERS = 'members';
    public const OFFICERS = 'officers';

    public function canView(string $visibility, bool $isMember, bool $isOfficer): bool
    {
        return match ($visibility) {
            self::PUBLIC => true,
            self::MEMBERS => $isMember,
            self::OFFICERS => $isOfficer,
            default => false,
        };
    }

    public function canViewPreparation(bool $isOfficer): bool
    {
        return $isOfficer;
    }
}
