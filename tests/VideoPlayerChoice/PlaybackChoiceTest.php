<?php

declare(strict_types=1);

namespace App\Tests\VideoPlayerChoice;

use App\VideoPlayerChoice\IntegrationCatalogue;
use App\VideoPlayerChoice\PlaybackChoice;
use App\VideoPlayerChoice\PlayerChoiceWidgetProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PlaybackChoiceTest extends TestCase
{
    public function testEngineChoiceCannotExtractAnIframeStream(): void
    {
        $choices = new PlaybackChoice(); $options = $choices->options(Request::create('/', 'POST'));
        self::assertSame(['native', 'videojs'], array_keys($choices->engines(['mode' => 'hls', 'url' => 'https://cdn.example.test/index.m3u8'])));
        self::assertSame(['embed'], array_keys($choices->engines(['mode' => 'iframe', 'url' => 'https://player.vimeo.com/video/12345'])));
        self::assertSame(['embed', 'youtube'], array_keys($choices->engines(['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/abcdef12345'])));
        self::assertSame(['embed'], array_keys($choices->engines(['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com.evil.test/embed/abcdef12345'])));
        $this->expectException(\InvalidArgumentException::class);
        $choices->select(['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/abcdef12345'], 'videojs', $options);
    }
    public function testYoutubeSettingsUseOnlyDocumentedParametersAndPreserveOfficialPlayer(): void
    {
        $choices = new PlaybackChoice();
        $options = $choices->options(Request::create('/', 'POST', ['start' => '42', 'language' => 'en', 'loop' => '1', 'captions' => '1', 'autoplay' => '1', 'muted' => '1']));
        $playback = $choices->select(['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/abcdef12345'], 'youtube', $options);
        parse_str((string) parse_url($playback['url'], PHP_URL_QUERY), $query);
        self::assertSame('42', $query['start']); self::assertSame('abcdef12345', $query['playlist']);
        self::assertSame('1', $query['controls']); self::assertSame('1', $query['cc_load_policy']);
        self::assertSame('en', $query['hl']); self::assertArrayNotHasKey('modestbranding', $query);
    }
    public function testMalformedSettingsAreRejected(): void
    {
        $choices = new PlaybackChoice();
        foreach ([['start' => '-1'], ['start' => '86401'], ['start' => '1&origin=evil'], ['speed' => '99'], ['language' => '<script>'], ['loop' => 'yes']] as $fields) {
            try { $choices->options(Request::create('/', 'POST', $fields)); self::fail('Invalid settings accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testRequestedIntegrationListIsCompleteAndOnlyInstalledOriginalIsActive(): void
    {
        $entries = (new IntegrationCatalogue())->entries();
        self::assertCount(17, $entries);
        self::assertCount(17, array_unique(array_column($entries, 'name')));
        $active = array_values(array_filter($entries, static fn (array $entry): bool => $entry['status'] === 'Direkt nutzbar'));
        self::assertCount(1, $active); self::assertSame('Video.js (Yii2)', $active[0]['name']);
        self::assertSame('Identifikation offen', $entries[16]['status']);
    }
}
