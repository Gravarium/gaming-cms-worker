<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\SiteSettings;
use App\Form\SiteSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class SiteSettingsHomeTitleBoundaryTest extends KernelTestCase
{
    public function testAcceptsHomeTitleAtPersistedUnicodeLimit(): void
    {
        $form = $this->submitHomeTitle(str_repeat('é', 180));

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('homeTitle')->isValid());
    }

    public function testRejectsHomeTitleBeyondPersistedUnicodeLimitAtFieldLevel(): void
    {
        $form = $this->submitHomeTitle(str_repeat('é', 181));

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('homeTitle')->isValid());
    }

    private function submitHomeTitle(string $homeTitle): FormInterface
    {
        $formFactory = static::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        $form = $formFactory->create(SiteSettingsType::class, new SiteSettings(), [
            'csrf_protection' => false,
        ]);
        $form->submit(['homeTitle' => $homeTitle], false);

        return $form;
    }
}
