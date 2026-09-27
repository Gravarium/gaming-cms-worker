<?php

declare(strict_types=1);

namespace App\Video\Discovery;

use App\Entity\VideoDiscovery\VideoLiveStream;

final class LiveEmbedPolicy
{
    /** @return array{provider:string,url:string}|null */
    public function resolve(VideoLiveStream $stream, string $parentHost, bool $consented): ?array
    {
        if (!$stream->isEnabled() || !$consented) {
            return null;
        }

        $parts = parse_url($stream->getSourceUrl());
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $parent = strtolower((string) preg_replace('/:\d+$/', '', trim($parentHost)));
        if ($parent === ''
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $parent) !== 1
        ) {
            return null;
        }

        return match ($stream->getProvider()) {
            'youtube' => $this->youtube($host, $path),
            'vimeo' => $this->vimeo($host, $path),
            'twitch' => $this->twitch($host, $path, $parent),
            default => null,
        };
    }

    /** @return array{provider:string,url:string}|null */
    private function youtube(string $host, string $path): ?array
    {
        if (!($host === 'youtu.be' || $host === 'www.youtu.be' || $host === 'youtube.com' || str_ends_with($host, '.youtube.com'))) {
            return null;
        }

        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $id = $path;
        } elseif (preg_match('~^(?:live|embed)/([A-Za-z0-9_-]{6,20})$~', $path, $matches) === 1) {
            $id = $matches[1];
        } else {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/D', $id) !== 1) {
            return null;
        }

        return ['provider' => 'youtube', 'url' => 'https://www.youtube-nocookie.com/embed/'.$id];
    }

    /** @return array{provider:string,url:string}|null */
    private function vimeo(string $host, string $path): ?array
    {
        if (!($host === 'vimeo.com' || str_ends_with($host, '.vimeo.com'))
            || preg_match('~^(\d+)$~', $path, $matches) !== 1
        ) {
            return null;
        }

        return ['provider' => 'vimeo', 'url' => 'https://player.vimeo.com/video/'.$matches[1]];
    }

    /** @return array{provider:string,url:string}|null */
    private function twitch(string $host, string $path, string $parent): ?array
    {
        if (!($host === 'twitch.tv' || str_ends_with($host, '.twitch.tv'))
            || preg_match('/^[A-Za-z0-9_]+$/D', $path) !== 1
        ) {
            return null;
        }

        return ['provider' => 'twitch', 'url' => 'https://player.twitch.tv/?channel='.$path.'&parent='.rawurlencode($parent)];
    }
}
