<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final readonly class ExtensionPackageInstaller
{
    public function __construct(
        private ExtensionPackageVerifier $verifier,
        private string $installRoot,
    ) {
    }

    public function install(string $packageDirectory): ExtensionManifest
    {
        $manifest = $this->verifier->verify($packageDirectory);
        $root = rtrim($this->installRoot, '/');
        if ($root === '' || is_link($root)) {
            throw new \DomainException('Extension install root may not be a symbolic link.');
        }
        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new \RuntimeException('Extension install root cannot be created.');
        }
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) {
            throw new \RuntimeException('Extension install root cannot be resolved.');
        }
        $root = $resolvedRoot;
        $typeRoot = $root.'/'.$manifest->type;
        $target = $typeRoot.'/'.$manifest->key;
        $stage = $root.'/.stage-'.$manifest->type.'-'.$manifest->key.'-'.bin2hex(random_bytes(6));
        $rollback = $root.'/.rollback-'.$manifest->type.'-'.$manifest->key.'-'.bin2hex(random_bytes(6));

        if (is_link($typeRoot) || is_link($target)) {
            throw new \DomainException('Extension install paths may not be symbolic links.');
        }
        if (!is_dir($typeRoot) && !mkdir($typeRoot, 0750, true) && !is_dir($typeRoot)) {
            throw new \RuntimeException('Extension install root cannot be created.');
        }
        $resolvedTypeRoot = realpath($typeRoot);
        if ($resolvedTypeRoot === false || dirname($resolvedTypeRoot) !== $root) {
            throw new \DomainException('Extension type install root escapes the configured install root.');
        }

        try {
            $this->copyPackage($packageDirectory, $stage);
            $stagedManifest = $this->verifier->verify($stage);
            if (!$this->sameManifest($manifest, $stagedManifest)) {
                throw new \DomainException('Extension package changed after its initial verification.');
            }

            if (is_dir($target) && !rename($target, $rollback)) {
                throw new \RuntimeException('Existing extension could not be staged for rollback.');
            }
            if (!rename($stage, $target)) {
                if (is_dir($rollback)) {
                    rename($rollback, $target);
                }
                throw new \RuntimeException('Verified extension could not be activated.');
            }
            if (is_dir($rollback)) {
                $this->removeTree($rollback);
            }
        } catch (\Throwable $exception) {
            if (is_dir($stage)) {
                $this->removeTree($stage);
            }
            if (!is_dir($target) && is_dir($rollback)) {
                rename($rollback, $target);
            }
            throw $exception;
        }

        return $manifest;
    }

    private function sameManifest(ExtensionManifest $expected, ExtensionManifest $actual): bool
    {
        return $expected->type === $actual->type
            && $expected->key === $actual->key
            && $expected->name === $actual->name
            && $expected->version === $actual->version
            && $expected->cmsConstraint === $actual->cmsConstraint
            && $expected->files === $actual->files
            && $expected->capabilities === $actual->capabilities;
    }

    private function copyPackage(string $source, string $target): void
    {
        if (!mkdir($target, 0750, true) && !is_dir($target)) {
            throw new \RuntimeException('Extension staging directory cannot be created.');
        }
        $source = (string) realpath($source);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \DomainException('Extension packages may not contain symbolic links.');
            }
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target.'/'.$relative;
            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0750, true) && !is_dir($destination)) {
                    throw new \RuntimeException('Extension directory could not be staged.');
                }
            } elseif ($item->isFile()) {
                if (!copy($item->getPathname(), $destination)) {
                    throw new \RuntimeException('Extension file could not be staged.');
                }
            } else {
                throw new \DomainException('Extension packages may contain only regular files and directories.');
            }
        }
    }

    private function removeTree(string $root): void
    {
        if (is_link($root)) {
            throw new \DomainException('Extension cleanup may not follow symbolic links.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                unlink($item->getPathname());
                continue;
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
