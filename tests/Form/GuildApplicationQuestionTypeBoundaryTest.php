<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\GuildApplicationQuestion;
use App\Form\GuildApplicationQuestionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildApplicationQuestionTypeBoundaryTest extends KernelTestCase
{
    public function testAcceptsLabelAtPersistedUnicodeLimit(): void
    {
        $form = $this->submitLabel(str_repeat('é', 255));

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('label')->isValid());
    }

    public function testRejectsLabelBeyondPersistedUnicodeLimitAtFieldLevel(): void
    {
        $form = $this->submitLabel(str_repeat('é', 256));

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('label')->isValid());
    }

    public function testLabelInputAdvertisesPersistedMaximum(): void
    {
        $form = $this->createQuestionForm();
        $view = $form->createView();

        self::assertSame(255, $view->children['label']->vars['attr']['maxlength']);
    }

    public function testLabelRemainsNonBlankWithoutSetterTypeError(): void
    {
        $form = $this->submitLabel('');

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('label')->isValid());
    }

    private function submitLabel(string $label): FormInterface
    {
        $form = $this->createQuestionForm();
        $form->submit([
            'label' => $label,
            'helpText' => '',
            'type' => GuildApplicationQuestion::TYPE_TEXT,
            'position' => 0,
            'required' => false,
            'enabled' => true,
        ]);

        return $form;
    }

    private function createQuestionForm(): FormInterface
    {
        $formFactory = static::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        return $formFactory->create(GuildApplicationQuestionType::class, new GuildApplicationQuestion(), [
            'csrf_protection' => false,
        ]);
    }
}
