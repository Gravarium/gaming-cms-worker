<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class S3ObjectStorage
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(string:S3_ENDPOINT)%')]
        private readonly string $endpoint,
        #[Autowire('%env(string:S3_REGION)%')]
        private readonly string $region,
        #[Autowire('%env(string:S3_BUCKET)%')]
        private readonly string $bucket,
        #[Autowire('%env(string:S3_ACCESS_KEY)%')]
        private readonly string $accessKey,
        #[Autowire('%env(string:S3_SECRET_KEY)%')]
        private readonly string $secretKey,
        #[Autowire('%env(string:S3_PUBLIC_URL)%')]
        private readonly string $publicUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== ''
            && $this->region !== ''
            && $this->bucket !== ''
            && $this->accessKey !== ''
            && $this->secretKey !== '';
    }

    public function upload(
        string $objectKey,
        string $filePath,
        ?string $contentType = null,
        ?string $modulePublicUrl = null,
    ): string {
        $this->assertConfigured();
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('Die hochzuladende Datei ist nicht lesbar.');
        }

        $payloadHash = hash_file('sha256', $filePath);
        if ($payloadHash === false) {
            throw new \RuntimeException('Die Prüfsumme der Datei konnte nicht erstellt werden.');
        }
        [$url, $headers, $encodedKey] = $this->signedRequest('PUT', $objectKey, $payloadHash);
        $headers['Content-Type'] = $contentType ?: 'application/octet-stream';

        $stream = fopen($filePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Die Datei konnte nicht geöffnet werden.');
        }

        try {
            $response = $this->httpClient->request('PUT', $url, ['headers' => $headers, 'body' => $stream]);
            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException('Der externe Speicher hat den Upload mit HTTP '.$statusCode.' abgelehnt.');
            }
        } finally {
            fclose($stream);
        }

        $modulePublicUrl = trim((string) $modulePublicUrl);
        if ($modulePublicUrl !== '') {
            return rtrim($modulePublicUrl, '/').'/'.rawurlencode(basename($objectKey));
        }
        if ($this->publicUrl !== '') {
            return rtrim($this->publicUrl, '/').'/'.$encodedKey;
        }

        return $url;
    }

    public function checkConnection(): void
    {
        $this->assertConfigured();
        [$url, $headers] = $this->signedRequest('HEAD', '', hash('sha256', ''));
        $statusCode = $this->httpClient->request('HEAD', $url, ['headers' => $headers])->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Der externe Speicher ist mit der hinterlegten Konfiguration nicht erreichbar.');
        }
    }

    public function delete(string $objectKey): void
    {
        $this->assertConfigured();
        [$url, $headers] = $this->signedRequest('DELETE', $objectKey, hash('sha256', ''));
        $statusCode = $this->httpClient->request('DELETE', $url, ['headers' => $headers])->getStatusCode();

        if (($statusCode < 200 || $statusCode >= 300) && $statusCode !== 404) {
            throw new \RuntimeException('Der externe Speicher hat das Löschen mit HTTP '.$statusCode.' abgelehnt.');
        }
    }

    /** @return array{string, array<string, string>, string} */
    private function signedRequest(string $method, string $objectKey, string $payloadHash): array
    {
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($objectKey, '/'))));
        $url = rtrim($this->endpoint, '/').'/'.rawurlencode($this->bucket).'/'.$encodedKey;
        $urlParts = parse_url($url);
        if (!is_array($urlParts) || !isset($urlParts['host'], $urlParts['path'])) {
            throw new \DomainException('Der konfigurierte S3-Endpunkt ist ungültig.');
        }

        $host = $urlParts['host'].(isset($urlParts['port']) ? ':'.$urlParts['port'] : '');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $canonicalHeaders = 'host:'.$host."\n"
            .'x-amz-content-sha256:'.$payloadHash."\n"
            .'x-amz-date:'.$amzDate."\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = $method."\n".$urlParts['path']."\n\n".$canonicalHeaders."\n".$signedHeaders."\n".$payloadHash;
        $credentialScope = $dateStamp.'/'.$this->region.'/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n".$amzDate."\n".$credentialScope."\n".hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        return [
            $url,
            [
                'Authorization' => 'AWS4-HMAC-SHA256 Credential='.$this->accessKey.'/'.$credentialScope
                    .', SignedHeaders='.$signedHeaders.', Signature='.$signature,
                'x-amz-content-sha256' => $payloadHash,
                'x-amz-date' => $amzDate,
            ],
            $encodedKey,
        ];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \DomainException('Der externe S3-Speicher ist noch nicht vollständig konfiguriert.');
        }
    }

    private function signingKey(string $dateStamp): string
    {
        $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4'.$this->secretKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }
}
