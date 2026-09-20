<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class S3MediaTargetConfigurationProvider
{
    /** @var array<string, array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string, public_url: string}>|null */
    private ?array $configurations = null;

    public function __construct(
        #[Autowire('%env(string:MEDIA_S3_CONFIG_FILE)%')]
        private readonly string $configurationFile,
    ) {
    }

    /** @return array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string, public_url: string} */
    public function forReference(string $reference): array
    {
        $reference = strtolower(trim($reference));
        $configurations = $this->load();

        return $configurations[$reference]
            ?? throw new \RuntimeException('No private S3 media configuration exists for the selected reference.');
    }

    /** @return array<string, array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string, public_url: string}> */
    private function load(): array
    {
        if ($this->configurations !== null) {
            return $this->configurations;
        }

        $path = trim($this->configurationFile);
        if ($path === '' || !str_starts_with($path, '/') || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('The private S3 media configuration file is unavailable.');
        }

        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 0004) !== 0) {
            throw new \RuntimeException('The private S3 media configuration file must not be world-readable.');
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('The private S3 media configuration file is invalid.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('The private S3 media configuration file is invalid.');
        }

        $configurations = [];
        foreach ($decoded as $reference => $values) {
            if (!is_string($reference) || preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $reference) !== 1 || !is_array($values)) {
                throw new \RuntimeException('The private S3 media configuration file contains an invalid entry.');
            }

            $configuration = [];
            foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key'] as $field) {
                $value = $values[$field] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    throw new \RuntimeException('The private S3 media configuration file contains an incomplete entry.');
                }
                $configuration[$field] = trim($value);
            }

            $publicUrl = $values['public_url'] ?? '';
            if (!is_string($publicUrl)) {
                throw new \RuntimeException('The private S3 media configuration file contains an invalid public URL.');
            }

            if (!$this->isHttpsUrl($configuration['endpoint'])
                || (trim($publicUrl) !== '' && !$this->isHttpsUrl(trim($publicUrl)))) {
                throw new \RuntimeException('S3 media endpoint and public URL must use HTTPS.');
            }

            $configuration['endpoint'] = rtrim($configuration['endpoint'], '/');
            $configuration['public_url'] = rtrim(trim($publicUrl), '/');
            /** @var array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string, public_url: string} $configuration */
            $configurations[$reference] = $configuration;
        }

        return $this->configurations = $configurations;
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host']);
    }
}
