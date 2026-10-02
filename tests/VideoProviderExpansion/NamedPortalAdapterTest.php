<?php

declare(strict_types=1);

namespace App\Tests\VideoProviderExpansion;

use App\Service\MediaUrlPolicy;
use App\Service\VideoEmbedResolver;
use App\VideoProviderExpansion\NamedPortalAdapter;
use App\VideoWorkspace\ProviderRegistry;
use App\VideoWorkspace\StandardProviderAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamedPortalAdapterTest extends TestCase
{
    /** @return iterable<string, array{string,string,string,string}> */
    public static function playableSources(): iterable
    {
        yield 'ScreenPal official iframe' => ['screenpal', 'https://go.screenpal.com/player/c0jrbPVp0Zm', 'iframe', 'https://go.screenpal.com/player/c0jrbPVp0Zm'];
        yield 'Kinescope no-brand embed' => ['kinescope', 'https://kinescope.io/202589431', 'iframe', 'https://kinescope.io/embed/202589431'];
        yield 'Kinescope supplied HLS manifest' => ['kinescope', 'https://kinescope.io/203613411/master.m3u8', 'hls', 'https://kinescope.io/203613411/master.m3u8'];
        yield 'Archive item embed' => ['internetarchive', 'https://archive.org/details/example-video', 'iframe', 'https://archive.org/embed/example-video'];
        yield 'Archive supplied file' => ['internetarchive', 'https://archive.org/download/example-video/clip.mp4', 'video', 'https://archive.org/download/example-video/clip.mp4'];
        yield 'Videco player' => ['videco', 'https://app.videco.io/v/demo123', 'iframe', 'https://app.videco.io/embed/demo123'];
        yield 'Kapwing supplied embed' => ['kapwing', 'https://www.kapwing.com/e/646e7619dfa8110017d35454', 'iframe', 'https://www.kapwing.com/e/646e7619dfa8110017d35454'];
        yield 'Kaltura documented iframe' => ['kaltura', 'https://cdnapisec.kaltura.com/p/4834032/embedPlaykitJs/uiconf_id/50952692?entry_id=1_fwzaeesq&iframeembed=true', 'iframe', 'https://cdnapisec.kaltura.com/p/4834032/embedPlaykitJs/uiconf_id/50952692?iframeembed=true&entry_id=1_fwzaeesq'];
        yield 'Kaltura signed embed' => ['kaltura', 'https://cdnapisec.kaltura.com/p/4834032/embedPlaykitJs/uiconf_id/50952692?entry_id=1_fwzaeesq&ks=abcdefghijklmnopqrstuvwxyz1234', 'iframe', 'https://cdnapisec.kaltura.com/p/4834032/embedPlaykitJs/uiconf_id/50952692?iframeembed=true&entry_id=1_fwzaeesq&ks=abcdefghijklmnopqrstuvwxyz1234'];
        yield 'YourImageShare direct video' => ['yourimageshare', 'https://yourimageshare.com/ib/aB3xY9qRz1.mp4', 'video', 'https://yourimageshare.com/ib/aB3xY9qRz1.mp4'];
        yield 'DBimg supplied file' => ['dbimg', 'https://dbimg.app/ib/abcdef1234.webm', 'video', 'https://dbimg.app/ib/abcdef1234.webm'];
        yield 'Vidzflow supplied file' => ['vidzflow', 'https://vidzflow.com/media/abcdef1234.mp4', 'video', 'https://vidzflow.com/media/abcdef1234.mp4'];
        yield 'VdoHide link-only' => ['vdohide', 'https://vdohide.com/watch/demo123', 'link', 'https://vdohide.com/watch/demo123'];
        yield 'GrooveVideo supplied iframe' => ['groovevideo', 'https://app.groove.cm/grooveembeds/video/70356/he5QXtUV7MI5pyz91Hdx', 'iframe', 'https://app.groove.cm/grooveembeds/video/70356/he5QXtUV7MI5pyz91Hdx'];
        yield 'Viddler supplied iframe' => ['viddler', 'https://www.viddler.com/embed/4c57d97a/?f=1&secret=34213636', 'iframe', 'https://www.viddler.com/embed/4c57d97a?f=1&secret=34213636'];
        yield 'MyVRSpot supplied iframe' => ['myvideospot', 'https://live.myvrspot.com/iframe?v=ODFiZTQwZDA3NjkyNzIxNDkwYWJkZDk2OWFiMjgyYzA', 'iframe', 'https://live.myvrspot.com/iframe?v=ODFiZTQwZDA3NjkyNzIxNDkwYWJkZDk2OWFiMjgyYzA'];
        yield 'GrooveVideo legacy page remains a link' => ['groovevideo', 'https://app.groovefunnels.com/grooveembeds/video/demo123/test-video', 'link', 'https://app.groovefunnels.com/grooveembeds/video/demo123/test-video'];
        yield 'Viddler unverified page remains a link' => ['viddler', 'https://www.viddler.com/v/demo123', 'link', 'https://www.viddler.com/v/demo123'];
        yield 'MyVideoSpot unverified path remains a link' => ['myvideospot', 'https://live.myvrspot.com/iframe/demo123', 'link', 'https://live.myvrspot.com/iframe/demo123'];
    }

    #[DataProvider('playableSources')]
    public function testSuppliedSourceUsesOnlyItsDocumentedCapability(string $provider, string $input, string $mode, string $output): void
    {
        self::assertSame(['mode' => $mode, 'url' => $output], $this->registry()->resolve($provider, $input, 'portal.example.test'));
    }

    /** @return iterable<string, array{string,string}> */
    public static function unsafeSources(): iterable
    {
        yield 'lookalike' => ['screenpal', 'https://screenpal.com.attacker.test/player/c0jrbPVp0Zm'];
        yield 'credentials' => ['internetarchive', 'https://user:pass@archive.org/details/example-video'];
        yield 'insecure' => ['kinescope', 'http://kinescope.io/embed/202589431'];
        yield 'nonstandard port' => ['kapwing', 'https://www.kapwing.com:444/e/646e7619dfa8110017d35454'];
        yield 'local network' => ['dbimg', 'https://127.0.0.1/ib/abcdef1234.mp4'];
        yield 'wrong media' => ['yourimageshare', 'https://yourimageshare.com/ib/abcdef1234.svg'];
        yield 'unsafe Kaltura entry' => ['kaltura', 'https://cdnapisec.kaltura.com/p/4834032/embedPlaykitJs/uiconf_id/50952692?entry_id=javascript:alert(1)'];
        yield 'unsafe Kaltura playback token' => ['kaltura', 'https://cdnapisec.kaltura.com/p/4834032/embedPlaykitJs/uiconf_id/50952692?entry_id=1_fwzaeesq&ks=<script>'];
        yield 'malformed Kapwing' => ['kapwing', 'https://www.kapwing.com/e/../../admin'];
        yield 'provider mismatch' => ['videco', 'https://www.kapwing.com/e/646e7619dfa8110017d35454'];
        yield 'fragment on embed' => ['screenpal', 'https://go.screenpal.com/player/c0jrbPVp0Zm#different'];
        yield 'arbitrary Kinescope path' => ['kinescope', 'https://kinescope.io/admin/users'];
        yield 'unknown provider' => ['not-a-provider', 'https://screenpal.com/player/c0jrbPVp0Zm'];
        yield 'GrooveVideo lookalike' => ['groovevideo', 'https://app.groove.cm.attacker.test/grooveembeds/video/70356/he5QXtUV7MI5pyz91Hdx'];
        yield 'GrooveVideo arbitrary query' => ['groovevideo', 'https://app.groove.cm/grooveembeds/video/70356/he5QXtUV7MI5pyz91Hdx?next=https://attacker.test'];
        yield 'Viddler attacker parameter' => ['viddler', 'https://www.viddler.com/embed/4c57d97a/?secret=34213636&next=https://attacker.test'];
        yield 'Viddler duplicate secret' => ['viddler', 'https://www.viddler.com/embed/4c57d97a/?secret=34213636&secret=12345678'];
        yield 'MyVRSpot unexpected query' => ['myvideospot', 'https://live.myvrspot.com/iframe?v=ODFiZTQwZDA3NjkyNzIxNDkwYWJkZDk2OWFiMjgyYzA&next=evil'];
        yield 'MyVRSpot token missing' => ['myvideospot', 'https://live.myvrspot.com/iframe?v='];
    }

    #[DataProvider('unsafeSources')]
    public function testUnsafeOrUnrelatedSourcesDoNotPlay(string $provider, string $url): void
    {
        self::assertNull($this->registry()->resolve($provider, $url, 'portal.example.test'));
    }

    public function testNoDuplicateIdsWithOriginalProviders(): void
    {
        $policy = new MediaUrlPolicy();
        $catalogue = (new ProviderRegistry([
            new StandardProviderAdapter(new VideoEmbedResolver($policy)),
            new NamedPortalAdapter(),
        ], $policy))->catalogue();
        self::assertCount(29, $catalogue);
        self::assertSame('embed', $catalogue['peertube']['capability']);
        self::assertSame('embed', $catalogue['dailymotion']['capability']);
        self::assertSame('embed_or_direct', $catalogue['kinescope']['capability']);
    }

    private function registry(): ProviderRegistry
    {
        return new ProviderRegistry([new NamedPortalAdapter()], new MediaUrlPolicy());
    }
}
