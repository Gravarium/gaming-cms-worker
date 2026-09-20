<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;

final class VideoEmbedResolver
{
    /** @return array{mode: string, url: string}|null */
    public function resolve(Video $video, string $parentHost): ?array
    {
        $url = $video->getPlaybackUrl();
        if ($url === null || $url === '') {
            return null;
        }

        return match ($video->getSourceType()) {
            Video::SOURCE_UPLOAD, Video::SOURCE_EXTERNAL => ['mode' => 'video', 'url' => $url],
            Video::SOURCE_YOUTUBE => $this->youtube($url),
            Video::SOURCE_VIMEO => $this->vimeo($url),
            Video::SOURCE_TWITCH => $this->twitch($url, $parentHost),
            default => null,
        };
    }

    /** @return array{mode: string, url: string}|null */
    private function youtube(string $url): ?array
    {
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $id = null;
        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $id = trim((string) ($parts['path'] ?? ''), '/');
        } elseif (str_ends_with($host, 'youtube.com')) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $id = $query['v'] ?? null;
            if ($id === null && preg_match('~/(?:shorts|embed)/([A-Za-z0-9_-]+)~', (string) ($parts['path'] ?? ''), $match)) {
                $id = $match[1];
            }
        }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            return null;
        }

        return ['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/'.$id];
    }

    /** @return array{mode: string, url: string}|null */
    private function vimeo(string $url): ?array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!preg_match('~/(\d+)(?:$|/)~', $path, $match)) {
            return null;
        }

        return ['mode' => 'iframe', 'url' => 'https://player.vimeo.com/video/'.$match[1]];
    }

    /** @return array{mode: string, url: string}|null */
    private function twitch(string $url, string $parentHost): ?array
    {
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $parent = rawurlencode(preg_replace('/:\d+$/', '', $parentHost) ?: 'localhost');

        if ($host === 'clips.twitch.tv' && preg_match('/^([A-Za-z0-9_-]+)$/', $path, $match)) {
            return ['mode' => 'iframe', 'url' => 'https://clips.twitch.tv/embed?clip='.$match[1].'&parent='.$parent];
        }
        if (str_ends_with($host, 'twitch.tv') && preg_match('~^videos/(\d+)$~', $path, $match)) {
            return ['mode' => 'iframe', 'url' => 'https://player.twitch.tv/?video=v'.$match[1].'&parent='.$parent];
        }
        if (str_ends_with($host, 'twitch.tv') && preg_match('/^([A-Za-z0-9_]+)$/', $path, $match)) {
            return ['mode' => 'iframe', 'url' => 'https://player.twitch.tv/?channel='.$match[1].'&parent='.$parent];
        }

        return null;
    }
}
