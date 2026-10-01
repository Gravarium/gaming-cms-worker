<?php

declare(strict_types=1);

namespace App\VideoWorkspace;

use App\Entity\Video;
use App\Service\VideoEmbedResolver;

final readonly class StandardProviderAdapter implements ProviderAdapter
{
    public function __construct(private VideoEmbedResolver $legacy) {}

    /** @return array<string, array{label:string, documentation:string, capability:string}> */
    public function catalogue(): array
    {
        $entries = [
            'youtube' => ['YouTube', 'https://developers.google.com/youtube/player_parameters', 'embed'],
            'vimeo' => ['Vimeo', 'https://help.vimeo.com/', 'embed'],
            'twitch' => ['Twitch', 'https://dev.twitch.tv/docs/embed/', 'embed'],
            'voe' => ['VOE', 'https://voe.sx/api-1-reference-index', 'embed'],
            'doodstream' => ['Doodstream', 'https://help.doodstream.com/en/article/how-to-get-embed-code-altpnu/', 'embed'],
            'filemoon' => ['Filemoon', 'https://filemoon.org/en/api-docs', 'embed_or_link'],
            'vidmoly' => ['Vidmoly', 'https://vidmoly.me/faq', 'link'],
            'dailymotion' => ['Dailymotion', 'https://developers.dailymotion.com/reference/migration-guide-new-embed-endpoint', 'embed'],
            'cloudflare' => ['Cloudflare Stream', 'https://developers.cloudflare.com/stream/viewing-videos/using-the-stream-player/', 'embed'],
            'peertube' => ['PeerTube', 'https://docs.joinpeertube.org/api/embed-player', 'embed'],
            'mp4' => ['MP4 · eigener Player', 'https://developer.mozilla.org/en-US/docs/Web/HTML/Element/video', 'direct'],
            'webm' => ['WebM · eigener Player', 'https://developer.mozilla.org/en-US/docs/Web/HTML/Element/video', 'direct'],
            'hls' => ['HLS · eigener Player / Livestream', 'https://github.com/video-dev/hls.js', 'direct'],
        ];
        $result = [];
        foreach ($entries as $key => [$label, $documentation, $capability]) {
            $result[$key] = compact('label', 'documentation', 'capability');
        }
        return $result;
    }

    /** @return array{mode:string,url:string}|null */
    public function resolve(string $provider, string $sourceUrl, string $parentHost): ?array
    {
        $parts = parse_url($sourceUrl);
        if (!is_array($parts) || isset($parts['port']) && $parts['port'] !== 443) { return null; }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (in_array($provider, ['mp4', 'webm', 'hls'], true)) {
            $extension = $provider === 'hls' ? 'm3u8' : $provider;
            return preg_match('~\.'.preg_quote($extension, '~').'\z~i', $path) === 1
                ? ['mode' => $provider === 'hls' ? 'hls' : 'video', 'url' => $sourceUrl] : null;
        }
        if (in_array($provider, ['youtube', 'vimeo', 'twitch'], true)) {
            $video = (new Video())->setSourceType($provider)->setSourceUrl($sourceUrl);
            return $this->legacy->resolve($video, $parentHost);
        }
        if ($provider === 'voe' && in_array($host, ['voe.sx', 'www.voe.sx'], true)
            && preg_match('~\A/(?:e/)?([a-zA-Z0-9]{12})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://voe.sx/e/'.$m[1]];
        }
        if ($provider === 'doodstream' && in_array($host, ['doodstream.com', 'dood.to', 'dood.watch', 'doodstream.co'], true)
            && preg_match('~\A/(?:e|d)/([a-zA-Z0-9]{6,32})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://'.$host.'/e/'.$m[1]];
        }
        if ($provider === 'filemoon' && $host === 'filemoon.org'
            && preg_match('~\A/([a-zA-Z0-9]{6,32})/embed/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://filemoon.org/'.$m[1].'/embed'];
        }
        if (($provider === 'filemoon' && in_array($host, ['filemoon.sx', 'filemoon.to', 'filemoon.org'], true))
            || ($provider === 'vidmoly' && in_array($host, ['vidmoly.me', 'vidmoly.to', 'vidmoly.net'], true))) {
            return ['mode' => 'link', 'url' => $sourceUrl];
        }
        if ($provider === 'dailymotion') {
            if (in_array($host, ['www.dailymotion.com', 'dailymotion.com'], true)
                && preg_match('~\A/(?:embed/)?video/([a-zA-Z0-9]{5,20})/?\z~', $path, $m) === 1) {
                return ['mode' => 'iframe', 'url' => 'https://www.dailymotion.com/embed/video/'.$m[1]];
            }
            if ($host === 'geo.dailymotion.com' && preg_match('~\A/player/[a-zA-Z0-9]+\.html\z~', $path) === 1) {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $id = $query['video'] ?? null;
                return is_string($id) && preg_match('/\A[a-zA-Z0-9]{5,20}\z/', $id) === 1
                    ? ['mode' => 'iframe', 'url' => 'https://'.$host.$path.'?video='.$id] : null;
            }
        }
        if ($provider === 'cloudflare' && preg_match('/\Acustomer-[a-z0-9]+\.cloudflarestream\.com\z/', $host) === 1
            && preg_match('~\A/([a-zA-Z0-9._-]{8,600})/iframe\z~', $path) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://'.$host.$path];
        }
        if ($provider === 'peertube' && preg_match('~\A/videos/embed/[0-9a-f-]{36}\z~', $path) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://'.$host.$path];
        }
        return null;
    }
}
