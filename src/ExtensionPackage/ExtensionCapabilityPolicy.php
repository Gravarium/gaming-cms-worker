<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final class ExtensionCapabilityPolicy
{
    /** @var list<string> */
    public const DECLARABLE = [
        'content.read',
        'content.write',
        'media.read',
        'media.write',
        'notifications.send',
        'http.outbound',
        'scheduler.register',
        'settings.read',
        'settings.write',
    ];

    /** @var list<string> */
    public const NEVER_GRANT = [
        'php.execute',
        'database.migrate',
        'filesystem.unrestricted',
        'process.execute',
        'secrets.read',
    ];

    /** @param mixed $requested
     *  @return list<string>
     */
    public function normalize(mixed $requested): array
    {
        if (!is_array($requested) || !array_is_list($requested)) {
            throw new \DomainException('Extension capabilities must be a JSON list.');
        }

        $capabilities = [];
        foreach ($requested as $capability) {
            if (!is_string($capability)
                || in_array($capability, self::NEVER_GRANT, true)
                || !in_array($capability, self::DECLARABLE, true)
            ) {
                throw new \DomainException('Extension requests a forbidden or unknown capability.');
            }
            $capabilities[] = $capability;
        }

        sort($capabilities);
        return array_values(array_unique($capabilities));
    }

    public function isGrantable(string $capability): bool
    {
        return in_array($capability, self::DECLARABLE, true);
    }
}
