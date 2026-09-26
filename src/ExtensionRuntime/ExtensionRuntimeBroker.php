<?php

declare(strict_types=1);

namespace App\ExtensionRuntime;

use App\ExtensionPackage\ExtensionCapabilityGate;
use App\ExternalConnector\ExternalNotificationDispatcher;
use App\ExternalConnector\ExternalNotificationMessage;
use App\Repository\ContentEntryRepository;
use App\Repository\MediaAssetRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ExtensionRuntimeBroker
{
    private const MAX_JSON_RESPONSE_BYTES = 262144;

    public function __construct(
        private ExtensionCapabilityGate $capabilities,
        private ExtensionRuntimeState $state,
        private ExtensionRuntimeAudit $audit,
        private ExtensionOutboundUrlPolicy $urls,
        private ContentEntryRepository $content,
        private MediaAssetRepository $media,
        private ExternalNotificationDispatcher $notifications,
        private HttpClientInterface $http,
    ) {
    }

    /** @return list<array{id:int,type:string,title:string,slug:string,excerpt:?string,publishedAt:?string}> */
    public function publishedContent(ExtensionRuntimeContext $context): array
    {
        return $this->run($context, 'content.read', function (): array {
            $items = array_slice($this->content->findPublishedNews(), 0, 25);
            return array_map(static fn ($entry): array => [
                'id' => (int) $entry->getId(),
                'type' => $entry->getType(),
                'title' => mb_substr($entry->getTitle(), 0, 180),
                'slug' => $entry->getSlug(),
                'excerpt' => $entry->getExcerpt() === null ? null : mb_substr($entry->getExcerpt(), 0, 500),
                'publishedAt' => $entry->getPublishedAt()?->format(DATE_ATOM),
            ], $items);
        });
    }

    /** @return list<array{id:int,name:string,mime:?string,size:?int,createdAt:string}> */
    public function mediaLibrary(ExtensionRuntimeContext $context): array
    {
        return $this->run($context, 'media.read', function () use ($context): array {
            $items = array_slice($this->media->searchLibrary(null, $context->manifest->key, null, null), 0, 25);
            return array_map(static fn ($asset): array => [
                'id' => (int) $asset->getId(),
                'name' => mb_substr($asset->getOriginalName(), 0, 255),
                'mime' => $asset->getMimeType(),
                'size' => $asset->getFileSize(),
                'createdAt' => $asset->getCreatedAt()->format(DATE_ATOM),
            ], $items);
        });
    }

    public function sendNotification(
        ExtensionRuntimeContext $context,
        string $type,
        string $title,
        string $message,
        ?string $link = null,
    ): void {
        $this->run($context, 'notifications.send', function () use ($type, $title, $message, $link): null {
            if (mb_strlen($type) > 80 || mb_strlen($title) > 160 || mb_strlen($message) > 2000
                || $link !== null && (mb_strlen($link) > 500 || !str_starts_with($link, 'https://'))
            ) {
                throw new \DomainException('Extension notification exceeds its safe contract.');
            }
            $this->notifications->send(new ExternalNotificationMessage($type, $title, $message, $link));
            return null;
        });
    }

    /** @return array{status:int,contentType:string,body:string} */
    public function fetchJson(ExtensionRuntimeContext $context, string $url): array
    {
        return $this->run($context, 'http.outbound', function () use ($url): array {
            $approved = $this->urls->approve($url);
            $response = $this->http->request('GET', $approved['url'], [
                'timeout' => 5,
                'max_duration' => 8,
                'max_redirects' => 0,
                'buffer' => false,
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'GamingCMS-Extension/1.0'],
                'resolve' => [$approved['host'] => $approved['ips'][0]],
            ]);
            $headers = $response->getHeaders(false);
            $contentType = strtolower((string) ($headers['content-type'][0] ?? ''));
            if (!str_starts_with($contentType, 'application/json')) {
                $response->cancel();
                throw new \DomainException('Extension HTTP response is not JSON.');
            }

            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();
                    throw new \DomainException('Extension HTTP response timed out.');
                }
                if ($chunk->isFirst() || $chunk->isLast()) {
                    continue;
                }

                $content = $chunk->getContent();
                if (strlen($body) + strlen($content) > self::MAX_JSON_RESPONSE_BYTES) {
                    $response->cancel();
                    throw new \DomainException('Extension HTTP response exceeds 256 KiB.');
                }
                $body .= $content;
            }

            json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            return ['status' => $response->getStatusCode(), 'contentType' => $contentType, 'body' => $body];
        });
    }

    private function run(ExtensionRuntimeContext $context, string $operation, callable $callback): mixed
    {
        $id = $context->id();
        try {
            $this->capabilities->require($context->manifest, $operation);
            $this->state->assertCircuitClosed($id);
            $this->state->consume($id, $operation);
        } catch (\DomainException $exception) {
            $this->audit->record($id, $operation, 'denied');
            throw $exception;
        }

        try {
            $result = $callback();
            $this->state->success($id);
            $this->audit->record($id, $operation, 'success');
            return $result;
        } catch (\Throwable $exception) {
            $this->state->failure($id);
            $this->audit->record($id, $operation, 'failed');
            throw $exception;
        }
    }
}
