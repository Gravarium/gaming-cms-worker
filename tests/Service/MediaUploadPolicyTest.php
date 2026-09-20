<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MediaMalwareScanner;
use App\Service\MediaUploadPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaUploadPolicyTest extends TestCase
{
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

    private function policy(): MediaUploadPolicy
    {
        return new MediaUploadPolicy(new MediaMalwareScanner('off', ''));
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-policy-');
        if ($path === false) {
            throw new \RuntimeException('Temporary file unavailable.');
        }
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }
}
