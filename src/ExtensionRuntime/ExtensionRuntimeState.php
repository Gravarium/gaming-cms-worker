<?php

declare(strict_types=1);

namespace App\ExtensionRuntime;

/**
 * @phpstan-type RuntimeState array{
 *     quotas: array<string, array{bucket: int, count: int}>,
 *     circuits: array<string, array{failures: int, openUntil: int}>
 * }
 */
final readonly class ExtensionRuntimeState
{
    private const LIMITS = [
        'content.read' => 120,
        'media.read' => 120,
        'notifications.send' => 20,
        'http.outbound' => 30,
    ];

    public const MAX_STATE_BYTES = 262144;
    public const MAX_STATE_ENTRIES = 2048;
    private const MAX_QUOTA_KEY_BYTES = 80;

    public function __construct(private string $stateFile)
    {
    }

    public function consume(string $extensionId, string $operation): void
    {
        $this->assertValidExtensionId($extensionId);
        if (!array_key_exists($operation, self::LIMITS)) {
            throw new \InvalidArgumentException('Invalid extension runtime operation.');
        }
        $limit = self::LIMITS[$operation];

        $this->mutate(function (array $state) use ($extensionId, $operation, $limit): array {
            $now = time();
            $bucket = intdiv($now, 60);
            $key = $extensionId.'|'.$operation;
            if (!array_key_exists($key, $state['quotas'])) {
                $this->assertCapacityForNewEntry($state);
            }

            $entry = $state['quotas'][$key] ?? ['bucket' => $bucket, 'count' => 0];
            if ($entry['bucket'] !== $bucket) {
                $entry = ['bucket' => $bucket, 'count' => 0];
            }
            if ($entry['count'] >= $limit) {
                throw new \DomainException('Extension runtime rate limit exceeded.');
            }

            ++$entry['count'];
            $state['quotas'][$key] = $entry;

            return $state;
        });
    }

    public function assertCircuitClosed(string $extensionId): void
    {
        $this->assertValidExtensionId($extensionId);
        $state = $this->read();
        $openUntil = $state['circuits'][$extensionId]['openUntil'] ?? 0;
        if ($openUntil > time()) {
            throw new \DomainException('Extension runtime circuit is temporarily open.');
        }
    }

    public function success(string $extensionId): void
    {
        $this->assertValidExtensionId($extensionId);
        $this->mutate(function (array $state) use ($extensionId): array {
            unset($state['circuits'][$extensionId]);

            return $state;
        });
    }

    public function failure(string $extensionId): void
    {
        $this->assertValidExtensionId($extensionId);
        $this->mutate(function (array $state) use ($extensionId): array {
            if (!array_key_exists($extensionId, $state['circuits'])) {
                $this->assertCapacityForNewEntry($state);
            }
            $entry = $state['circuits'][$extensionId] ?? ['failures' => 0, 'openUntil' => 0];
            if ($entry['failures'] < PHP_INT_MAX) {
                ++$entry['failures'];
            }
            if ($entry['failures'] >= 5) {
                $entry['openUntil'] = time() + 900;
            }
            $state['circuits'][$extensionId] = $entry;

            return $state;
        });
    }

    /** @return array{circuit: string, failures: int, requestsThisMinute: int} */
    public function sanitizedStatus(string $extensionId): array
    {
        $this->assertValidExtensionId($extensionId);
        $state = $this->read();
        $circuit = $state['circuits'][$extensionId] ?? null;
        $requests = 0;
        $now = time();
        $bucket = intdiv($now, 60);

        foreach ($state['quotas'] as $key => $entry) {
            if (str_starts_with($key, $extensionId.'|') && $entry['bucket'] === $bucket) {
                $requests += $entry['count'];
            }
        }

        return [
            'circuit' => $circuit !== null && $circuit['openUntil'] > $now ? 'open' : 'closed',
            'failures' => $circuit['failures'] ?? 0,
            'requestsThisMinute' => $requests,
        ];
    }

    /**
     * @param callable(RuntimeState): RuntimeState $callback
     */
    private function mutate(callable $callback): void
    {
        $directory = dirname($this->stateFile);
        if (is_link($directory) || is_link($this->stateFile)) {
            throw new \DomainException('Extension runtime state may not use symbolic links.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Runtime state directory unavailable.');
        }

        $handle = fopen($this->stateFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Runtime state lock unavailable.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('Runtime state lock unavailable.');
        }

        try {
            if (!rewind($handle)) {
                throw new \RuntimeException('Runtime state could not be read.');
            }
            $raw = stream_get_contents($handle, self::MAX_STATE_BYTES + 1);
            if (!is_string($raw)) {
                throw new \RuntimeException('Runtime state could not be read.');
            }

            $state = $callback($this->decodeState($raw));
            $json = $this->encodeState($state);
            if (strlen($json) > self::MAX_STATE_BYTES) {
                throw new \DomainException('Extension runtime state exceeds its safe size limit.');
            }

            if (!rewind($handle) || !ftruncate($handle, 0)) {
                throw new \RuntimeException('Runtime state could not be updated.');
            }
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('Runtime state could not be updated.');
            }
            chmod($this->stateFile, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return RuntimeState */
    private function read(): array
    {
        if (is_link(dirname($this->stateFile)) || is_link($this->stateFile)) {
            throw new \DomainException('Extension runtime state may not use symbolic links.');
        }
        if (!is_file($this->stateFile)) {
            return $this->emptyState();
        }

        $handle = @fopen($this->stateFile, 'rb');
        if ($handle === false) {
            throw new \DomainException('Extension runtime state is unavailable.');
        }
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new \RuntimeException('Runtime state lock unavailable.');
        }

        try {
            $raw = stream_get_contents($handle, self::MAX_STATE_BYTES + 1);
            if (!is_string($raw)) {
                throw new \DomainException('Extension runtime state is invalid.');
            }

            return $this->decodeState($raw);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return RuntimeState */
    private function decodeState(string $raw): array
    {
        if (strlen($raw) > self::MAX_STATE_BYTES) {
            throw new \DomainException('Extension runtime state exceeds its safe size limit.');
        }
        if ($raw === '') {
            return $this->emptyState();
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException('Extension runtime state is invalid.', 0, $exception);
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \DomainException('Extension runtime state has an invalid shape.');
        }
        // Earlier versions wrote [] when the last circuit entry was cleared.
        if ($decoded === []) {
            return $this->emptyState();
        }

        foreach (array_keys($decoded) as $key) {
            if (!is_string($key) || !in_array($key, ['quotas', 'circuits'], true)) {
                throw new \DomainException('Extension runtime state has an invalid shape.');
            }
        }

        $quotaValue = array_key_exists('quotas', $decoded) ? $decoded['quotas'] : [];
        $circuitValue = array_key_exists('circuits', $decoded) ? $decoded['circuits'] : [];
        if (!is_array($quotaValue) || ($quotaValue !== [] && array_is_list($quotaValue))
            || !is_array($circuitValue) || ($circuitValue !== [] && array_is_list($circuitValue))
        ) {
            throw new \DomainException('Extension runtime state has an invalid shape.');
        }

        $quotas = [];
        foreach ($quotaValue as $key => $entry) {
            if (!is_string($key) || strlen($key) > self::MAX_QUOTA_KEY_BYTES) {
                throw new \DomainException('Extension runtime quota state is invalid.');
            }
            $separator = strrpos($key, '|');
            if ($separator === false) {
                throw new \DomainException('Extension runtime quota state is invalid.');
            }
            $extensionId = substr($key, 0, $separator);
            $operation = substr($key, $separator + 1);
            if (!$this->isValidExtensionId($extensionId) || !array_key_exists($operation, self::LIMITS)
                || !is_array($entry) || count($entry) !== 2
                || !array_key_exists('bucket', $entry) || !is_int($entry['bucket'])
                || !array_key_exists('count', $entry) || !is_int($entry['count'])
                || $entry['bucket'] < 0 || $entry['count'] < 1 || $entry['count'] > self::LIMITS[$operation]
            ) {
                throw new \DomainException('Extension runtime quota state is invalid.');
            }

            $quotas[$key] = ['bucket' => $entry['bucket'], 'count' => $entry['count']];
        }

        $circuits = [];
        foreach ($circuitValue as $extensionId => $entry) {
            if (!is_string($extensionId) || !$this->isValidExtensionId($extensionId)
                || !is_array($entry) || count($entry) !== 2
                || !array_key_exists('failures', $entry) || !is_int($entry['failures'])
                || !array_key_exists('openUntil', $entry) || !is_int($entry['openUntil'])
                || $entry['failures'] < 0 || $entry['openUntil'] < 0
            ) {
                throw new \DomainException('Extension runtime circuit state is invalid.');
            }

            $circuits[$extensionId] = ['failures' => $entry['failures'], 'openUntil' => $entry['openUntil']];
        }

        if (count($quotas) + count($circuits) > self::MAX_STATE_ENTRIES) {
            throw new \DomainException('Extension runtime state exceeds its safe entry limit.');
        }

        return ['quotas' => $quotas, 'circuits' => $circuits];
    }

    /**
     * @param RuntimeState $state
     */
    private function encodeState(array $state): string
    {
        return json_encode([
            'quotas' => (object) $state['quotas'],
            'circuits' => (object) $state['circuits'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param RuntimeState $state
     */
    private function assertCapacityForNewEntry(array $state): void
    {
        if (count($state['quotas']) + count($state['circuits']) >= self::MAX_STATE_ENTRIES) {
            throw new \DomainException('Extension runtime state exceeds its safe entry limit.');
        }
    }

    /** @return RuntimeState */
    private function emptyState(): array
    {
        return ['quotas' => [], 'circuits' => []];
    }

    private function assertValidExtensionId(string $extensionId): void
    {
        if (!$this->isValidExtensionId($extensionId)) {
            throw new \InvalidArgumentException('Invalid extension runtime identity.');
        }
    }

    private function isValidExtensionId(string $extensionId): bool
    {
        return preg_match('/^(?:module|theme):[a-z][a-z0-9-]{1,39}$/D', $extensionId) === 1;
    }
}
