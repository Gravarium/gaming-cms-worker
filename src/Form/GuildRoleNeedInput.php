<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Guild\GuildRoleNeed;

final class GuildRoleNeedInput
{
    public string $roleKey = '';

    public string $classKey = '';

    public int $desiredCount = 1;

    public bool $active = true;

    public static function fromNeed(GuildRoleNeed $need): self
    {
        $input = new self();
        $input->desiredCount = $need->getDesiredCount();
        $input->active = $need->isActive();

        return $input;
    }
}
