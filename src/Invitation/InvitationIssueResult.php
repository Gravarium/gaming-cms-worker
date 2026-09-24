<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Entity\Invitation\MemberInvitation;

final readonly class InvitationIssueResult
{
    public function __construct(
        public MemberInvitation $invitation,
        public string $rawToken,
    ) {}
}
