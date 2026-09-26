<?php

declare(strict_types=1);

namespace App\Community\Interaction;

use App\Entity\ContentEntry;
use App\Module\CmsModuleManager;
use App\Repository\ContentEntryRepository;

final readonly class ContentEntryTargetProvider implements InteractionTargetProvider
{
    public const TYPE = 'content';

    public function __construct(
        private ContentEntryRepository $entries,
        private CmsModuleManager $modules,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function resolve(int $targetId): ?InteractionTargetContext
    {
        if ($targetId < 1) {
            return null;
        }

        $entry = $this->entries->find($targetId);
        if (!$entry instanceof ContentEntry) {
            return null;
        }

        return new InteractionTargetContext(
            self::TYPE,
            $targetId,
            $this->modules->isEnabled('content'),
            $entry->isPublished(),
            $entry->getAuthor()?->getId(),
        );
    }
}
