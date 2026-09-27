<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Entity\User;

final readonly class InvitationRegistrationResult
{
    public function __construct(
        public User $user,
        public bool $verificationMailQueued,
    ) {}
}
