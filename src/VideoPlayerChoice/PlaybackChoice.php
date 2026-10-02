<?php

declare(strict_types=1);

namespace App\VideoPlayerChoice;

use Symfony\Component\HttpFoundation\Request;

final class PlaybackChoice
{
    /** @param array{mode:string,url:string}|null $playback
     * @return array<string,string>
     */
    public function engines(?array $playback): array
    {
        if ($playback === null) { return []; }
        return match ($playback['mode']) {
            'video', 'hls' => ['native' => 'Browser-Player', 'videojs' => 'Video.js'],
            'iframe' => $this->youtube($playback['url'])
                ? ['embed' => 'YouTube Standard', 'youtube' => 'YouTube mit Einstellungen']
                : ['embed' => 'Anbieter-Player'],
            'link' => ['link' => 'Beim Anbieter öffnen'],
            default => [],
        };
    }

    /** @return array{start:int,speed:string,muted:bool,loop:bool,autoplay:bool,captions:bool,language:string} */
    public function options(Request $request): array
    {
        $start = $request->request->getString('start', '0');
        $speed = $request->request->getString('speed', '1');
        $language = $request->request->getString('language', 'de');
        if (preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/', $start) !== 1 || (int) $start > 86400
            || !in_array($speed, ['0.5', '0.75', '1', '1.25', '1.5', '2'], true)
            || !in_array($language, ['de', 'en', 'fr', 'es', 'it'], true)) {
            throw new \InvalidArgumentException('Bitte gültige Player-Einstellungen wählen.');
        }
        $flags = [];
        foreach (['muted', 'loop', 'autoplay', 'captions'] as $name) {
            $value = $request->request->getString($name, '0');
            if (!in_array($value, ['0', '1'], true)) { throw new \InvalidArgumentException('Ungültige Player-Einstellung.'); }
            $flags[$name] = $value === '1';
        }
        return ['start' => (int) $start, 'speed' => $speed, 'language' => $language,
            'muted' => $flags['muted'], 'loop' => $flags['loop'], 'autoplay' => $flags['autoplay'], 'captions' => $flags['captions']];
    }

    /** @param array{mode:string,url:string} $playback
     * @param array{start:int,speed:string,muted:bool,loop:bool,autoplay:bool,captions:bool,language:string} $options
     * @return array{mode:string,url:string}
     */
    public function select(array $playback, string $engine, array $options): array
    {
        if (!array_key_exists($engine, $this->engines($playback))) {
            throw new \InvalidArgumentException('Dieser Player passt nicht zur gewählten Quelle.');
        }
        if ($engine !== 'youtube') { return $playback; }
        $id = basename((string) parse_url($playback['url'], PHP_URL_PATH));
        $query = ['playsinline' => '1', 'controls' => '1', 'start' => (string) $options['start'],
            'hl' => $options['language'], 'cc_lang_pref' => $options['language'],
            'cc_load_policy' => $options['captions'] ? '1' : '0', 'autoplay' => $options['autoplay'] ? '1' : '0',
            'mute' => $options['muted'] ? '1' : '0', 'loop' => $options['loop'] ? '1' : '0'];
        if ($options['loop']) { $query['playlist'] = $id; }
        return ['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/'.$id.'?'.http_build_query($query)];
    }

    private function youtube(string $url): bool
    {
        return preg_match('~\Ahttps://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{6,20}(?:\?[^#]*)?\z~', $url) === 1;
    }
}
