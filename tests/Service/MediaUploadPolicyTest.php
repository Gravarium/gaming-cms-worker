<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MediaMalwareScanner;
use App\Service\MediaUploadPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaUploadPolicyTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testAllowsMatchingServerDetectedMimeAndExtension(): void
    {
        $file = $this->upload('notes.txt', 'safe text');
        $this->policy()->assertSafe($file, 'content');
        self::addToAssertionCount(1);
    }

    public function testRejectsDangerousDoubleExtension(): void
    {
        $file = $this->upload('avatar.php.txt', 'safe text');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('gefährliche Erweiterung');
        $this->policy()->assertSafe($file, 'content');
    }

    public function testRejectsPathLikeAndReservedNames(): void
    {
        foreach (['../notes.txt', 'folder\\notes.txt', 'CON.txt'] as $name) {
            try {
                $this->policy()->assertSafe($this->upload($name, 'safe text'), 'content');
                self::fail('Unsafe filename accepted: '.$name);
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsMalformedOriginalNameEncoding(): void
    {
        $file = $this->upload("notes".chr(0xB1).".txt", 'safe text');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ursprüngliche Dateiname');
        $this->policy()->assertSafe($file, 'content');
    }

    public function testRejectsInvalidModuleKeyBeforeStoragePathConstruction(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Zielmodul');

        $this->policy()->assertSafe($this->upload('notes.txt', 'safe text'), '../content');
    }

    public function testRejectsEmptyUpload(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('leer');

        $this->policy()->assertSafe($this->upload('empty.txt', ''), 'content');
    }

    public function testRejectsMimeExtensionMismatch(): void
    {
        $file = $this->upload('document.pdf', 'this is plain text');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Dateiendung');
        $this->policy()->assertSafe($file, 'content');
    }

    public function testRejectsSvgActiveContent(): void
    {
        $file = $this->upload('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('gefährliche Erweiterung');
        $this->policy()->assertSafe($file, 'content');
    }

    public function testRejectsImageWithExcessiveDimensions(): void
    {
        $file = $this->upload('huge.png', $this->pngHeader(9000, 1), 'image/png');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Bildabmessungen');
        $this->policy()->assertSafe($file, 'content');
    }

    public function testRejectsDeepJsonBeforeUnboundedDecode(): void
    {
        $json = str_repeat('[', 33).'0'.str_repeat(']', 33);
        $file = $this->upload('deep.json', $json, 'application/json');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('JSON-Datei');
        $this->policy()->assertSafe($file, 'content');
    }

    private function policy(): MediaUploadPolicy
    {
        return new MediaUploadPolicy(new MediaMalwareScanner('off', ''));
    }

    private function upload(string $name, string $contents, ?string $forcedMimeType = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-policy-');
        if ($path === false) {
            throw new \RuntimeException('Temporary file unavailable.');
        }
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return new class($path, $name, $forcedMimeType) extends UploadedFile {
            public function __construct(
                string $path,
                private readonly string $untrustedOriginalName,
                private readonly ?string $forcedMimeType,
            ) {
                parent::__construct($path, $untrustedOriginalName, null, UPLOAD_ERR_OK, true);
            }

            public function getClientOriginalName(): string
            {
                return $this->untrustedOriginalName;
            }

            public function getMimeType(): ?string
            {
                return $this->forcedMimeType ?? parent::getMimeType();
            }
        };
    }

    private function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('N2', $width, $height)."\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1A\n"
            .pack('N', strlen($ihdr))
            .$ihdr
            .pack('N', crc32($ihdr))
            .pack('N', 0)
            .'IEND'
            .pack('N', crc32('IEND'));
    }
}
