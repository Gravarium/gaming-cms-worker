<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\ModuleStorageSetting;
use App\Form\ModuleStorageSettingType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class ModuleStorageSettingTypeBoundaryTest extends KernelTestCase
{
    public function testExactUrlLimitIsAcceptedAndRendered(): void
    {
        $setting = $this->newSetting();
        $form = $this->createSettingsForm($setting);
        $url = $this->httpsUrlOfLength(500);
        $form->submit([
            'storageMode' => ModuleStorageSetting::MODE_EXTERNAL,
            'externalBaseUrl' => $url,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertCount(0, $form->get('externalBaseUrl')->getErrors());
        self::assertSame($url, $setting->getExternalBaseUrl());

        $view = $form->createView();
        self::assertSame(500, $view->children['externalBaseUrl']->vars['attr']['maxlength']);
    }

    public function testUrlAboveLimitHasAnExternalUrlFieldError(): void
    {
        $setting = $this->newSetting();
        $form = $this->createSettingsForm($setting);
        $form->submit([
            'storageMode' => ModuleStorageSetting::MODE_EXTERNAL,
            'externalBaseUrl' => $this->httpsUrlOfLength(501),
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('externalBaseUrl')->getErrors());
    }

    public function testBlankOptionalUrlRemainsValidAndNormalizesToNull(): void
    {
        $setting = $this->newSetting();
        $form = $this->createSettingsForm($setting);
        $form->submit([
            'storageMode' => ModuleStorageSetting::MODE_INTERNAL,
            'externalBaseUrl' => '',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertNull($setting->getExternalBaseUrl());
    }

    private function newSetting(): ModuleStorageSetting
    {
        return (new ModuleStorageSetting())->setModuleKey('url_boundary_'.bin2hex(random_bytes(8)));
    }

    private function createSettingsForm(ModuleStorageSetting $setting): FormInterface
    {
        self::bootKernel();

        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        return $formFactory->create(ModuleStorageSettingType::class, $setting, ['csrf_protection' => false]);
    }

    private function httpsUrlOfLength(int $length): string
    {
        $prefix = 'https://storage.example.com/';

        return $prefix.str_repeat('x', $length - strlen($prefix));
    }
}
