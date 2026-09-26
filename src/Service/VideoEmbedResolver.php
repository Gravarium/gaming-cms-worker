<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;

final readonly class VideoEmbedResolver
{
    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];
    private const SHORT_YOUTUBE_HOSTS = ['youtu.be', 'www.youtu.be'];
    private const VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com'];
    private const TWITCH_HOSTS = ['twitch.tv', 'www.twitch.tv'];

    public function __construct(private MediaUrlPolicy $urlPolicy)
    {
    }

    /** @return array{mode: string, url: string}|null */
    public function resolve(Video $video, string $parentHost): ?array
    {
        $url = $video->getPlaybackUrl();
        if ($url === null || $url === '') {
            return null;
        }

        return match ($video->getSourceType()) {
            Video::SOURCE_UPLOAD => $this->urlPolicy->isSafePlayback($url) ? ['mode' => 'video', 'url' => $url] : null,
            Video::SOURCE_EXTERNAL => $this->urlPolicy->isSafeRemote($url) ? ['mode' => 'video', 'url' => $url] : null,
            Video::SOURCE_YOUTUBE => $this->youtube($url),
            Video::SOURCE_VIMEO => $this->vimeo($url),
            Video::SOURCE_TWITCH => $this->twitch($url, $parentHost),
            default => null,
        };
    }

    /** @return array{mode: string, url: string}|null */
    private function youtube(string $url): ?array
    {
        if (!$this->urlPolicy->isSafeRemote($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $host = $this->normalizedHost((string) ($parts['host'] ?? ''));
        $id = null;
        if (in_array($host, self::SHORT_YOUTUBE_HOSTS, true)) {
            $id = trim((string) ($parts['path'] ?? ''), '/');
        } elseif (in_array($host, self::YOUTUBE_HOSTS, true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $id = $query['v'] ?? null;
            if ($id === null && preg_match('~/(?:shorts|embed)/([A-Za-z0-9_-]{6,20})(?:/|$)~', (string) ($parts['path'] ?? ''), $match)) {
                $id = $match[1];
            }
        }

        if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{6,20}\z/', $id) !== 1) {
            return null;
        }

        return ['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/'.$id];
    }

    /** @return array{mode: string, url: string}|null */
    private function vimeo(string $url): ?array
    {
        if (!$this->urlPolicy->isSafeRemote($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $host = $this->normalizedHost((string) ($parts['host'] ?? ''));
        if (!in_array($host, self::VIMEO_HOSTS, true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('~/(\d{1,20})(?:$|/)~', $path, $match) !== 1) {
            return null;
        }

        return ['mode' => 'iframe', 'url' => 'https://player.vimeo.com/video/'.$match[1]];
    }

    /** @return array{mode: string, url: string}|null */
    private function twitch(string $url, string $parentHost): ?array
    {
        if (!$this->urlPolicy->isSafeRemote($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $host = $this->normalizedHost((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $parent = $this->normalizedParentHost($parentHost);
        if ($parent === null) {
            return null;
        }

        if ($host === 'clips.twitch.tv' && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $path) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://clips.twitch.tv/embed?clip='.$path.'&parent='.rawurlencode($parent)];
        }
        if (in_array($host, self::TWITCH_HOSTS, true) && preg_match('~\Avideos/(\d{1,20})\z~', $path, $match) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://player.twitch.tv/?video=v'.$match[1].'&parent='.rawurlencode($parent)];
        }
        if (in_array($host, self::TWITCH_HOSTS, true) && preg_match('/\A[A-Za-z0-9_]{1,100}\z/', $path) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://player.twitch.tv/?channel='.$path.'&parent='.rawurlencode($parent)];
        }

        return null;
    }

    private function normalizedHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }

    private function normalizedParentHost(string $parentHost): ?string
    {
        $parentHost = trim($parentHost);
        if (preg_match('/:(\d+)\z/', $parentHost, $portMatch) === 1) {
            $port = (int) $portMatch[1];
            if ($port < 1 || $port > 65535) {
                return null;
            }
            $parentHost = substr($parentHost, 0, -strlen($portMatch[0]));
        }

        $parentHost = strtolower(rtrim($parentHost, '.'));
        if ($parentHost === '' || strlen($parentHost) > 253) {
            return null;
        }

        return preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/i', $parentHost) === 1
            ? $parentHost
            : null;
    }
}
