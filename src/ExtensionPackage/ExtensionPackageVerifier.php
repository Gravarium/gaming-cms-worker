<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final readonly class ExtensionPackageVerifier
{
    public const CMS_VERSION = '1.0.0';

    public function __construct(
        private string $trustedKeysFile,
        private ExtensionCapabilityPolicy $capabilityPolicy,
    ) {
    }

    public function verify(string $packageDirectory): ExtensionManifest
    {
        $root = realpath($packageDirectory);
        if ($root === false || !is_dir($root) || is_link($packageDirectory)) {
            throw new \DomainException('Extension package directory is invalid.');
        }

        $manifestPath = $root.'/manifest.json';
        $signaturePath = $root.'/signature.json';
        $raw = $this->read($manifestPath);
        $signature = $this->decodeJson($this->read($signaturePath), 'signature');
        $trustedKeys = $this->trustedKeys();

        $signer = $signature['signer'] ?? null;
        $encodedSignature = $signature['signature'] ?? null;
        if (!is_string($signer) || !is_string($encodedSignature) || !isset($trustedKeys[$signer])) {
            throw new \DomainException('Extension package signer is not trusted.');
        }

        $detached = base64_decode($encodedSignature, true);
        $publicKey = $trustedKeys[$signer];
        if ($detached === false || strlen($detached) !== SODIUM_CRYPTO_SIGN_BYTES
            || $publicKey === ''
            || !sodium_crypto_sign_verify_detached($detached, $raw, $publicKey)
        ) {
            throw new \DomainException('Extension package signature is invalid.');
        }

        $data = $this->decodeJson($raw, 'manifest');
        $allowedFields = ['schemaVersion', 'type', 'key', 'name', 'version', 'cmsConstraint', 'files', 'capabilities'];
        if (array_diff(array_keys($data), $allowedFields) !== [] || ($data['schemaVersion'] ?? null) !== 1) {
            throw new \DomainException('Extension manifest schema is not supported.');
        }

        $type = $data['type'] ?? null;
        $key = $data['key'] ?? null;
        $name = $data['name'] ?? null;
        $version = $data['version'] ?? null;
        $constraint = $data['cmsConstraint'] ?? null;
        $files = $data['files'] ?? null;
        $capabilities = $this->capabilityPolicy->normalize($data['capabilities'] ?? []);

        if (!in_array($type, ['module', 'theme'], true)
            || !is_string($key) || preg_match('/^[a-z][a-z0-9-]{1,39}$/', $key) !== 1
            || !is_string($name) || trim($name) === '' || mb_strlen($name) > 120
            || !is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1
            || !is_string($constraint) || !$this->compatible($constraint)
            || !is_array($files) || $files === []
        ) {
            throw new \DomainException('Extension manifest metadata is invalid or incompatible.');
        }

        $normalizedFiles = [];
        foreach ($files as $path => $hash) {
            if (!is_string($path) || !$this->safeRelativePath($path)
                || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
            ) {
                throw new \DomainException('Extension manifest contains an unsafe file entry.');
            }
            $fullPath = $root.'/'.$path;
            if (!is_file($fullPath) || is_link($fullPath) || !str_starts_with((string) realpath($fullPath), $root.'/')) {
                throw new \DomainException('Extension package file is missing or unsafe.');
            }
            $actualHash = hash_file('sha256', $fullPath);
            if ($actualHash === false || !hash_equals($hash, $actualHash)) {
                throw new \DomainException('Extension package checksum mismatch.');
            }
            $normalizedFiles[$path] = $hash;
        }

        $allowed = array_fill_keys(array_merge(['manifest.json', 'signature.json'], array_keys($normalizedFiles)), true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \DomainException('Extension packages may not contain symbolic links.');
            }
            if (!$item->isFile() && !$item->isDir()) {
                throw new \DomainException('Extension packages may contain only regular files and directories.');
            }
            if ($item->isFile()) {
                $relative = substr($item->getPathname(), strlen($root) + 1);
                if (!isset($allowed[$relative])) {
                    throw new \DomainException('Extension package contains an undeclared file.');
                }
            }
        }

        return new ExtensionManifest($type, $key, trim($name), $version, $constraint, $normalizedFiles, $capabilities);
    }

    private function compatible(string $constraint): bool
    {
        if (preg_match('/^\^(\d+)\.(\d+)$/', $constraint, $matches) !== 1) {
            return false;
        }
        [$major, $minor] = array_map('intval', array_slice(explode('.', self::CMS_VERSION), 0, 2));
        return $major === (int) $matches[1] && $minor >= (int) $matches[2];
    }

    private function safeRelativePath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 240 && !str_starts_with($path, '/')
            && !str_contains($path, '\\') && !str_contains($path, "\0")
            && preg_match('#(^|/)\.\.?(?:/|$)#', $path) !== 1
            && preg_match('#^[A-Za-z0-9._/-]+$#', $path) === 1;
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $json, string $kind): array
    {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('Extension '.$kind.' JSON is invalid.');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException('Extension '.$kind.' JSON must be an object.');
        }
        return $value;
    }

    /** @return array<string, string> */
    private function trustedKeys(): array
    {
        $path = realpath($this->trustedKeysFile);
        if ($path === false || !is_file($path) || is_link($this->trustedKeysFile)) {
            throw new \DomainException('Trusted extension key registry is unavailable.');
        }
        $raw = $this->decodeJson($this->read($path), 'trusted key registry');
        $keys = [];
        foreach ($raw as $id => $encoded) {
            if (preg_match('/^[a-zA-Z0-9._-]{1,80}$/', $id) !== 1 || !is_string($encoded)) {
                throw new \DomainException('Trusted extension key registry is invalid.');
            }
            $key = base64_decode($encoded, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \DomainException('Trusted extension public key is invalid.');
            }
            $keys[$id] = $key;
        }
        return $keys;
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            throw new \DomainException('Required extension package file is unreadable.');
        }
        return $contents;
    }
}
