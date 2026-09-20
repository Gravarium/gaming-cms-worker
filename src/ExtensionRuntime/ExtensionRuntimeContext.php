<?php
declare(strict_types=1);
namespace App\ExtensionRuntime;
use App\ExtensionPackage\ExtensionManifest;
final readonly class ExtensionRuntimeContext
{
    public function __construct(public ExtensionManifest $manifest) {}
    public function id(): string { return $this->manifest->type.':'.$this->manifest->key; }
}
