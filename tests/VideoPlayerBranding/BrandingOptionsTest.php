<?php

declare(strict_types=1);

namespace App\Tests\VideoPlayerBranding;

use App\VideoPlayerBranding\BrandingOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BrandingOptionsTest extends TestCase
{
    public function testDirectSourceMayBeUnbrandedOrUseOwnText(): void
    {
        $options = new BrandingOptions();
        self::assertSame('off', $options->parse(Request::create('/', 'POST', ['branding' => 'off']), 'video')['mode']);
        self::assertSame(['mode' => 'own', 'label' => 'Mein Kanal', 'color' => 'dark', 'position' => 'bottom-left'],
            $options->parse(Request::create('/', 'POST', ['branding' => 'own', 'brand_label' => ' Mein Kanal ', 'brand_color' => 'dark', 'brand_position' => 'bottom-left']), 'hls'));
    }

    public function testProviderSourcesNeverReceiveOwnBranding(): void
    {
        $options = new BrandingOptions();
        self::assertSame('provider', $options->parse(Request::create('/', 'POST', ['branding' => 'off']), 'iframe')['mode']);
        $this->expectException(\InvalidArgumentException::class);
        $options->parse(Request::create('/', 'POST', ['branding' => 'own']), 'iframe');
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidSettings(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BrandingOptions())->parse(Request::create('/', 'POST', $input), 'video');
    }

    /** @return iterable<string,array{array<string,string>}> */
    public static function invalidInputs(): iterable
    {
        yield 'unknown mode' => [['branding' => 'remove-provider-logo']];
        yield 'empty label' => [['branding' => 'own', 'brand_label' => ' ']];
        yield 'long label' => [['branding' => 'own', 'brand_label' => str_repeat('x', 41)]];
        yield 'invalid color' => [['brand_color' => 'transparent;url(evil)']];
        yield 'invalid position' => [['brand_position' => 'center']];
    }
}
