<?php

declare(strict_types=1);

namespace App\VideoProviderExpansion;

use App\VideoWorkspace\ProviderAdapter;

/** Accepts only supplied provider links; it never downloads pages or discovers media behind a player. */
final class NamedPortalAdapter implements ProviderAdapter
{
    /** @return array<string, array{label:string, documentation:string, capability:string}> */
    public function catalogue(): array
    {
        $entries = [
            'screenpal' => ['ScreenPal', 'https://support.screenpal.com/portal/en/kb/articles/embed-a-video-on-your-website', 'embed'],
            'kinescope' => ['Kinescope', 'https://docs.kinescope.com/video-player/embedding/', 'embed_or_direct'],
            'vdohide' => ['VdoHide', 'https://vdohide.com/', 'link'],
            'dbimg' => ['DBimg', 'https://dbimg.app/en/blog/best-free-image-hosting-no-account-2026', 'direct_or_link'],
            'internetarchive' => ['Internet Archive', 'https://archivesupport.zendesk.com/hc/en-us/articles/360018377531-Movies-and-Videos-Tips-Troubleshooting', 'embed_or_direct'],
            'groovevideo' => ['GrooveVideo', 'https://app.groove.cm/grooveembeds/video/70356/he5QXtUV7MI5pyz91Hdx', 'embed'],
            'viddler' => ['Viddler', 'https://github.com/viddler/Examples/blob/master/vapi/iframe/simple-example.html', 'embed_or_link'],
            'videco' => ['Videco', 'https://www.videco.io/help', 'embed'],
            'kapwing' => ['Kapwing', 'https://www.kapwing.com/tools/embed', 'embed_or_link'],
            'kaltura' => ['Kaltura', 'https://knowledge.kaltura.com/help/embed-code-reference', 'embed'],
            'myvideospot' => ['MyVideoSpot', 'https://docs.myvideospot.com/article/298-embedding-videos', 'embed_or_link'],
            'vidzflow' => ['Vidzflow', 'https://www.vidzflow.com/pricing', 'direct_or_link'],
            'yourimageshare' => ['YourImageShare', 'https://yourimageshare.com/video-hosting', 'direct_or_link'],
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
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['port']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        if ($provider === 'screenpal' && in_array($host, ['screenpal.com', 'go.screenpal.com'], true)
            && preg_match('~\A/player/([A-Za-z0-9]{6,32})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://'.$host.'/player/'.$m[1]];
        }
        if ($provider === 'kinescope' && $host === 'kinescope.io') {
            if (preg_match('~\A/([A-Za-z0-9]{8,32})/master\.m3u8\z~', $path) === 1) {
                return ['mode' => 'hls', 'url' => $sourceUrl];
            }
            if (preg_match('~\A/(?:embed/)?([A-Za-z0-9]{8,32})/?\z~', $path, $m) === 1 && $query === '') {
                return ['mode' => 'iframe', 'url' => 'https://kinescope.io/embed/'.$m[1]];
            }
        }
        if ($provider === 'internetarchive' && $host === 'archive.org') {
            if (preg_match('~\A/download/([A-Za-z0-9._-]{2,100})/([A-Za-z0-9._%+-]{1,180})\.(mp4|webm)\z~i', $path) === 1) {
                return ['mode' => 'video', 'url' => $sourceUrl];
            }
            if (preg_match('~\A/(?:details|embed)/([A-Za-z0-9._-]{2,100})/?\z~', $path, $m) === 1) {
                return ['mode' => 'iframe', 'url' => 'https://archive.org/embed/'.$m[1]];
            }
        }
        if ($provider === 'videco' && $host === 'app.videco.io'
            && preg_match('~\A/(?:embed|v)/([A-Za-z0-9_-]{1,100})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://app.videco.io/embed/'.$m[1]];
        }
        if ($provider === 'kapwing' && in_array($host, ['kapwing.com', 'www.kapwing.com'], true)
            && preg_match('~\A/e/([a-f0-9]{24})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://www.kapwing.com/e/'.$m[1]];
        }
        if ($provider === 'kaltura' && $host === 'cdnapisec.kaltura.com'
            && preg_match('~\A/p/([1-9][0-9]{0,11})/embedPlaykitJs/uiconf_id/([1-9][0-9]{0,11})\z~', $path, $m) === 1) {
            parse_str($query, $q);
            $id = $q['entry_id'] ?? null;
            if (!is_string($id) || preg_match('/\A[0-9]+_[A-Za-z0-9]{5,30}\z/', $id) !== 1) { return null; }
            $params = ['iframeembed' => 'true', 'entry_id' => $id];
            if (isset($q['ks'])) {
                if (!is_string($q['ks']) || preg_match('/\A[A-Za-z0-9._-]{10,500}\z/', $q['ks']) !== 1) { return null; }
                $params['ks'] = $q['ks'];
            }
            return ['mode' => 'iframe', 'url' => 'https://cdnapisec.kaltura.com'.$path.'?'.http_build_query($params)];
        }
        if ($provider === 'groovevideo' && $host === 'app.groove.cm' && $query === ''
            && preg_match('~\A/grooveembeds/video/([1-9][0-9]{0,11})/([A-Za-z0-9]{16,32})/?\z~', $path, $m) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://app.groove.cm/grooveembeds/video/'.$m[1].'/'.$m[2]];
        }
        if ($provider === 'viddler' && $host === 'www.viddler.com'
            && preg_match('~\A/embed/([a-f0-9]{8})/?\z~', $path, $m) === 1
            && preg_match('/\A(?:f=1(?:&secret=[0-9]{1,16})?|secret=[0-9]{1,16})?\z/', $query) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://www.viddler.com/embed/'.$m[1].($query === '' ? '' : '?'.$query)];
        }
        if ($provider === 'myvideospot' && $host === 'live.myvrspot.com' && $path === '/iframe'
            && preg_match('/\Av=([A-Za-z0-9_-]{20,128})(?:&share=1)?\z/', $query) === 1) {
            return ['mode' => 'iframe', 'url' => 'https://live.myvrspot.com/iframe?'.$query];
        }
        if ($provider === 'yourimageshare' && $host === 'yourimageshare.com'
            && preg_match('~\A/ib/[A-Za-z0-9_-]{6,80}\.(mp4|webm)\z~i', $path) === 1) {
            return ['mode' => 'video', 'url' => $sourceUrl];
        }
        if ($provider === 'dbimg' && in_array($host, ['dbimg.app', 'cdn.dbimg.app'], true)
            && preg_match('~\A/[A-Za-z0-9_./-]{2,180}\.(mp4|webm)\z~i', $path) === 1) {
            return ['mode' => 'video', 'url' => $sourceUrl];
        }
        if ($provider === 'vidzflow' && in_array($host, ['vidzflow.com', 'www.vidzflow.com'], true)
            && preg_match('~\A/[A-Za-z0-9_./-]{2,180}\.(mp4|webm)\z~i', $path) === 1) {
            return ['mode' => 'video', 'url' => $sourceUrl];
        }

        // A malformed iframe URL must not be silently downgraded to a link.
        if (($provider === 'groovevideo' && $host === 'app.groove.cm' && str_starts_with($path, '/grooveembeds/video/'))
            || ($provider === 'viddler' && $host === 'www.viddler.com' && str_starts_with($path, '/embed/'))
            || ($provider === 'myvideospot' && $host === 'live.myvrspot.com' && $path === '/iframe')) {
            return null;
        }

        // Unrecognized supplier pages remain outbound links, never guessed iframe sources.
        $linkHosts = [
            'vdohide' => ['vdohide.com', 'www.vdohide.com'],
            'dbimg' => ['dbimg.app', 'www.dbimg.app'],
            'groovevideo' => ['groovevideo.com', 'www.groovevideo.com', 'app.groovefunnels.com'],
            'viddler' => ['viddler.com', 'www.viddler.com'],
            'kapwing' => ['kapwing.com', 'www.kapwing.com'],
            'myvideospot' => ['myvideospot.com', 'www.myvideospot.com', 'live.myvideospot.com', 'live.myvrspot.com'],
            'vidzflow' => ['vidzflow.com', 'www.vidzflow.com'],
            'yourimageshare' => ['yourimageshare.com'],
        ];
        if (preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~', $path) === 1
            || ($provider === 'yourimageshare' && str_starts_with($path, '/ib/'))) {
            return null;
        }
        return isset($linkHosts[$provider]) && in_array($host, $linkHosts[$provider], true)
            && $path !== '' && $path !== '/' ? ['mode' => 'link', 'url' => $sourceUrl] : null;
    }
}
