<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\MediaAssetUploadType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaAssetUploadTypeBoundaryTest extends KernelTestCase
{
    private ?string $temporaryFile = null;

    public function testAcceptsUnicodeMetadataAtExistingLimits(): void
    {
        $form = $this->submitUploadForm(str_repeat('é', 180), str_repeat('é', 255));

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('title')->isValid());
        self::assertTrue($form->get('altText')->isValid());
    }

    public function testRejectsTitleBeyondExistingLimitAtFieldLevel(): void
    {
        $form = $this->submitUploadForm(str_repeat('é', 181), str_repeat('a', 255));

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('title')->isValid());
        self::assertTrue($form->get('altText')->isValid());
    }

    public function testRejectsAltTextBeyondExistingLimitAtFieldLevel(): void
    {
        $form = $this->submitUploadForm(str_repeat('a', 180), str_repeat('é', 256));

        self::assertFalse($form->isValid());
        self::assertTrue($form->get('title')->isValid());
        self::assertFalse($form->get('altText')->isValid());
    }

    protected function tearDown(): void
    {
        if ($this->temporaryFile !== null) {
            @unlink($this->temporaryFile);
            $this->temporaryFile = null;
        }

        parent::tearDown();
    }

    private function submitUploadForm(string $title, string $altText): FormInterface
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-upload-form-');
        self::assertNotFalse($temporaryFile);
        $this->temporaryFile = $temporaryFile;
        self::assertNotFalse(file_put_contents($temporaryFile, 'valid upload text'));

        $uploadedFile = new UploadedFile($temporaryFile, 'fixture.txt', 'text/plain', null, true);
        $formFactory = static::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        $form = $formFactory->create(MediaAssetUploadType::class, null, [
            'modules' => ['Gaming' => 'gaming'],
            'csrf_protection' => false,
        ]);
        $form->submit([
            'moduleKey' => 'gaming',
            'folder' => '',
            'file' => $uploadedFile,
            'title' => $title,
            'altText' => $altText,
            'caption' => '',
            'tags' => '',
        ]);

        return $form;
    }
}
