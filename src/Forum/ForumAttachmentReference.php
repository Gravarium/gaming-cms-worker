<?php

declare(strict_types=1);

namespace App\Forum;

final readonly class ForumAttachmentReference
{
    public function __construct(public string $type, public int $referenceId, public int $ownerId)
    {
        if (!in_array($type, ['media', 'download'], true) || $referenceId < 1 || $ownerId < 1) {
            throw new \InvalidArgumentException('Attachment must use a hardened managed reference.');
        }
    }

    public static function fromUrl(string $url): never
    {
        throw new \DomainException('Free attachment URLs bypassing hardened contracts are denied.');
    }
}
