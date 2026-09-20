<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

use App\ExtensionRuntime\ExtensionRuntimeState;

final readonly class ExtensionPackageInventory
{
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
        $items = [];
        foreach (['module', 'theme'] as $type) {
            $directories = glob(rtrim($this->installRoot, '/').'/'.$type.'/*', GLOB_ONLYDIR) ?: [];
            foreach ($directories as $directory) {
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
                } catch (\DomainException) {
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
}
