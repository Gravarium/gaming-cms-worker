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
            $now = time(); $bucket = intdiv($now, 60);
            $key = $extensionId.'|'.$operation;
            $entry = $state['quotas'][$key] ?? ['bucket' => $bucket, 'count' => 0];
            if (($entry['bucket'] ?? null) !== $bucket) { $entry = ['bucket' => $bucket, 'count' => 0]; }
            if ((int) $entry['count'] >= $limit) { throw new \DomainException('Extension runtime rate limit exceeded.'); }
            $entry['count'] = (int) $entry['count'] + 1;
            $state['quotas'][$key] = $entry;
            return $state;
        });
    }

    public function assertCircuitClosed(string $extensionId): void
    {
        $state = $this->read();
        $openUntil = (int) ($state['circuits'][$extensionId]['openUntil'] ?? 0);
        if ($openUntil > time()) { throw new \DomainException('Extension runtime circuit is temporarily open.'); }
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
            if ($entry['failures'] >= 5) { $entry['openUntil'] = time() + 900; }
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
        if (is_link($directory) || is_link($this->stateFile)) { throw new \DomainException('Extension runtime state may not use symbolic links.'); }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Runtime state directory unavailable.'); }
        $handle = fopen($this->stateFile, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Runtime state lock unavailable.'); }
        try {
            rewind($handle); $raw = stream_get_contents($handle);
            $state = $raw === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($state)) { $state = []; }
            $state = $callback($state);
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle); ftruncate($handle, 0); fwrite($handle, $json); fflush($handle); chmod($this->stateFile, 0600);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if (is_link($this->stateFile)) { throw new \DomainException('Extension runtime state may not be a symbolic link.'); }
        if (!is_file($this->stateFile)) { return []; }
        try { $state = json_decode((string) file_get_contents($this->stateFile), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \DomainException('Extension runtime state is invalid.'); }
        return is_array($state) ? $state : [];
    }
}
