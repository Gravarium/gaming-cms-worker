<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

use App\ExtensionRuntime\ExtensionRuntimeState;

final readonly class ExtensionPackageInventory
{
    private const MAX_INSTALL_ROOT_PATH_BYTES = 4000;
    private const MAX_PACKAGE_COUNT = 500;

    public function __construct(
        private ExtensionPackageVerifier $verifier,
        private ExtensionPermissionStore $permissions,
        private ExtensionRuntimeState $runtimeState,
        private string $installRoot,
    ) {
    }

    /** @return list<array{type:string,key:string,name:string,version:string,status:string,requested:list<string>,approved:list<string>}> */
    public function all(): array
    {
        if (
            !self::safeInstallRoot($this->installRoot)
            || !is_dir($this->installRoot)
            || is_link($this->installRoot)
            || !is_readable($this->installRoot)
        ) {
            return [];
        }

        $items = [];
        $packageCount = 0;
        foreach (['module', 'theme'] as $type) {
            $directories = $this->directories($type);
            if ($directories === null) {
                return [];
            }

            foreach ($directories as $directory) {
                if (++$packageCount > self::MAX_PACKAGE_COUNT) {
                    return [];
                }

                $key = basename($directory);
                try {
                    $manifest = $this->verifier->verify($directory);
                    $items[] = [
                        'type' => $manifest->type,
                        'key' => $manifest->key,
                        'name' => $manifest->name,
                        'version' => $manifest->version,
                        'status' => 'verified',
                        'requested' => $manifest->capabilities,
                        'approved' => $this->permissions->approved($manifest),
                        'runtime' => $this->runtimeState->sanitizedStatus($manifest->type.':'.$manifest->key),
                    ];
                } catch (\DomainException|\InvalidArgumentException) {
                    $items[] = [
                        'type' => $type,
                        'key' => preg_match('/^[a-z][a-z0-9-]{1,39}$/', $key) === 1 ? $key : 'invalid',
                        'name' => 'Ungültiges externes Paket',
                        'version' => '-',
                        'status' => 'invalid',
                        'requested' => [],
                        'approved' => [],
                        'runtime' => ['circuit' => 'open', 'failures' => 0, 'requestsThisMinute' => 0],
                    ];
                }
            }
        }

        usort($items, static fn (array $a, array $b): int => [$a['type'], $a['key']] <=> [$b['type'], $b['key']]);

        return $items;
    }

    /** @return list<string>|null */
    private function directories(string $type): ?array
    {
        $root = rtrim($this->installRoot, '/').'/'.$type;
        if (!is_dir($root) || is_link($root) || !is_readable($root)) {
            return [];
        }

        try {
            $iterator = new \DirectoryIterator($root);
            $directories = [];
            foreach ($iterator as $entry) {
                if ($entry->isDot() || $entry->isLink() || !$entry->isDir()) {
                    continue;
                }

                $directories[] = $entry->getPathname();
                if (count($directories) > self::MAX_PACKAGE_COUNT) {
                    return null;
                }
            }

            return $directories;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    private static function safeInstallRoot(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_INSTALL_ROOT_PATH_BYTES
            && mb_check_encoding($path, 'UTF-8')
            && str_starts_with($path, '/')
            && preg_match('/[\x00-\x1F\x7F]/', $path) === 0;
    }
}
