<?php

declare(strict_types=1);

namespace App\Community\Interaction;

final readonly class InteractionTargetRegistry
{
    /** @var array<string, InteractionTargetProvider> */
    private array $providers;

    public function __construct(?ContentEntryTargetProvider $content = null)
    {
        $providers = [];
        if ($content !== null) {
            $providers[$content->type()] = $content;
        }
        $this->providers = $providers;
    }

    public function supports(string $type): bool
    {
        return isset($this->providers[$type]);
    }

    public function resolve(string $type, int $targetId): ?InteractionTargetContext
    {
        if ($targetId < 1) {
            return null;
        }

        return $this->providers[$type]->resolve($targetId) ?? null;
    }
}
