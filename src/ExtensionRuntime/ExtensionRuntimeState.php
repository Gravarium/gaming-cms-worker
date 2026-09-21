<?php

declare(strict_types=1);

namespace App\ExtensionRuntime;

final readonly class ExtensionRuntimeState
{
    private const LIMITS = ['content.read' => 120, 'media.read' => 120, 'notifications.send' => 20, 'http.outbound' => 30];

    public function __construct(private string $stateFile) {}

    public function consume(string $extensionId, string $operation): void
    {
        $limit = self::LIMITS[$operation] ?? 10;
        $this->mutate(function (array $state) use ($extensionId, $operation, $limit): array {
            $now = time();
            $bucket = intdiv($now, 60);
            $key = $extensionId.'|'.$operation;
            $entry = $state['quotas'][$key] ?? ['bucket' => $bucket, 'count' => 0];
            if (($entry['bucket'] ?? null) !== $bucket) {
                $entry = ['bucket' => $bucket, 'count' => 0];
            }
            if ((int) $entry['count'] >= $limit) {
                throw new \DomainException('Extension runtime rate limit exceeded.');
            }
            $entry['count'] = (int) $entry['count'] + 1;
            $state['quotas'][$key] = $entry;

            return $state;
        });
    }

    public function assertCircuitClosed(string $extensionId): void
    {
        $state = $this->read();
        $openUntil = (int) ($state['circuits'][$extensionId]['openUntil'] ?? 0);
        if ($openUntil > time()) {
            throw new \DomainException('Extension runtime circuit is temporarily open.');
        }
    }

    public function success(string $extensionId): void
    {
        $this->mutate(function (array $state) use ($extensionId): array {
            unset($state['circuits'][$extensionId]);

            return $state;
        });
    }

    public function failure(string $extensionId): void
    {
        $this->mutate(function (array $state) use ($extensionId): array {
            $entry = $state['circuits'][$extensionId] ?? ['failures' => 0, 'openUntil' => 0];
            $entry['failures'] = (int) $entry['failures'] + 1;
            if ($entry['failures'] >= 5) {
                $entry['openUntil'] = time() + 900;
            }
            $state['circuits'][$extensionId] = $entry;

            return $state;
        });
    }

    /** @return array{circuit:string,failures:int,requestsThisMinute:int} */
    public function sanitizedStatus(string $extensionId): array
    {
        $state = $this->read();
        $circuit = $state['circuits'][$extensionId] ?? [];
        $requests = 0;
        $bucket = intdiv(time(), 60);
        foreach (($state['quotas'] ?? []) as $key => $entry) {
            if (str_starts_with((string) $key, $extensionId.'|') && ($entry['bucket'] ?? null) === $bucket) {
                $requests += (int) ($entry['count'] ?? 0);
            }
        }

        return [
            'circuit' => (int) ($circuit['openUntil'] ?? 0) > time() ? 'open' : 'closed',
            'failures' => (int) ($circuit['failures'] ?? 0),
            'requestsThisMinute' => $requests,
        ];
    }

    private function mutate(callable $callback): void
    {
        $directory = dirname($this->stateFile);
        if (is_link($directory)) {
            throw new \RuntimeException('Runtime state directory may not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Runtime state directory unavailable.');
        }
        if (is_link($this->stateFile)) {
            throw new \RuntimeException('Runtime state file may not be a symbolic link.');
        }

        $handle = fopen($this->stateFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Runtime state lock unavailable.');
        }
        try {
            $this->assertRegularOpenedFile($handle);
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Runtime state lock unavailable.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = $this->decodeState(is_string($raw) ? $raw : '');
            $state = $callback($state);
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !chmod($this->stateFile, 0600)) {
                throw new \RuntimeException('Runtime state cannot be written safely.');
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        if (is_link($this->stateFile)) {
            throw new \DomainException('Extension runtime state may not be a symbolic link.');
        }

        $handle = fopen($this->stateFile, 'rb');
        if ($handle === false) {
            throw new \DomainException('Extension runtime state is unreadable.');
        }
        try {
            $this->assertRegularOpenedFile($handle);
            if (!flock($handle, LOCK_SH)) {
                throw new \DomainException('Extension runtime state cannot be locked.');
            }
            $raw = stream_get_contents($handle);

            return $this->decodeState(is_string($raw) ? $raw : '');
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function decodeState(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('Extension runtime state is invalid.');
        }

        return is_array($state) && !array_is_list($state) ? $state : [];
    }

    /** @param resource $handle */
    private function assertRegularOpenedFile($handle): void
    {
        $opened = fstat($handle);
        $path = @lstat($this->stateFile);
        if ($opened === false || $path === false
            || ($path['mode'] & 0170000) !== 0100000
            || $opened['dev'] !== $path['dev']
            || $opened['ino'] !== $path['ino']
        ) {
            throw new \RuntimeException('Runtime state file changed or is not a regular file.');
        }
    }
}
