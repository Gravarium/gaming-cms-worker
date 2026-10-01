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
            'bunny' => ['Bunny Stream', 'https://bunny.net/blog/introducing-player-js-support-for-bunny-stream-advanced-player-control-and-monitoring-api/', 'embed'],
            'wistia' => ['Wistia', 'https://support.wistia.com/en/articles/9691677-wistia-inline-embeds', 'embed'],
            'streamable' => ['Streamable', 'https://streamable-support.zendesk.com/hc/en-us/articles/35415648975892-Editing-the-Embed-Code', 'embed'],
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
        if ($provider === 'youtube' && in_array($host, ['www.youtube-nocookie.com', 'youtube-nocookie.com', 'youtube.com', 'www.youtube.com'], true)
            && preg_match('~\A/(?:embed|live)/([A-Za-z0-9_-]{6,20})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/'.$m[1]];
        }
        if ($provider === 'vimeo' && in_array($host, ['player.vimeo.com', 'vimeo.com', 'www.vimeo.com'], true)
            && preg_match('~\A/(?:video/)?(\d{1,20})(?:/([a-f0-9]{10,32}))?/?\z~', $path, $m) === 1) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $hash = $query['h'] ?? ($m[2] ?? '');
            if (!is_string($hash) || ($hash !== '' && preg_match('/\A[a-f0-9]{10,32}\z/', $hash) !== 1)) { return null; }
            return ['mode' => 'iframe', 'url' => 'https://player.vimeo.com/video/'.$m[1].($hash === '' ? '' : '?h='.$hash)];
        }
        if ($provider === 'twitch' && in_array($host, ['player.twitch.tv', 'clips.twitch.tv'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if ($host === 'clips.twitch.tv' && $path === '/embed' && isset($query['clip']) && is_string($query['clip']) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $query['clip']) === 1) {
                $sourceUrl = 'https://clips.twitch.tv/'.$query['clip'];
            } elseif ($host === 'player.twitch.tv') {
                if (isset($query['channel']) && is_string($query['channel']) && preg_match('/\A[A-Za-z0-9_]{1,100}\z/', $query['channel']) === 1) { $sourceUrl = 'https://twitch.tv/'.$query['channel']; }
                elseif (isset($query['video']) && is_string($query['video']) && preg_match('/\Av?(\d{1,20})\z/', $query['video'], $m) === 1) { $sourceUrl = 'https://twitch.tv/videos/'.$m[1]; }
                else { return null; }
            }
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
        if ($provider === 'bunny' && $host === 'iframe.mediadelivery.net'
            && preg_match('~\A/embed/[0-9]{1,12}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z~', $path) === 1) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $auth = [];
            foreach (['token' => '/\A[a-f0-9]{64}\z/', 'expires' => '/\A[0-9]{1,12}\z/'] as $key => $pattern) {
                if (isset($query[$key])) {
                    if (!is_string($query[$key]) || preg_match($pattern, $query[$key]) !== 1) { return null; }
                    $auth[$key] = $query[$key];
                }
            }
            return ['mode' => 'iframe', 'url' => 'https://'.$host.$path.($auth === [] ? '' : '?'.http_build_query($auth))];
        }
        if ($provider === 'wistia' && in_array($host, ['fast.wistia.net', 'fast.wistia.com'], true)
            && preg_match('~\A/embed/iframe/([a-z0-9]{10})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://fast.wistia.net/embed/iframe/'.$m[1]];
        }
        if ($provider === 'streamable' && in_array($host, ['streamable.com', 'www.streamable.com'], true)
            && preg_match('~\A/(?:e/)?([a-z0-9]{4,20})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://streamable.com/e/'.$m[1]];
        }
        if ($provider === 'peertube'  && preg_match('~\A/videos/embed/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z~', $path) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://'.$host.$path];
        }
        return null;
    }
}
