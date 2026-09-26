<?php

declare(strict_types=1);

namespace App\Community\Interaction;

interface InteractionTargetProvider
{
    public function type(): string;

    public function resolve(int $targetId): ?InteractionTargetContext;
}
