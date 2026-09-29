<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\VideoCategory;
use App\Form\VideoCategoryType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class VideoCategoryTypeBoundaryTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testNameAcceptsTheExactPersistedColumnLength(): void
    {
        $form = $this->factory->create(VideoCategoryType::class, new VideoCategory());
        $name = str_repeat('v', 120);
        $form->submit(['name' => $name]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame($name, $form->getData()->getName());
        self::assertSame(120, $form->createView()->children['name']->vars['attr']['maxlength']);
    }

    public function testNameLongerThanTheColumnLengthHasAFieldError(): void
    {
        $form = $this->factory->create(VideoCategoryType::class, new VideoCategory());
        $form->submit(['name' => str_repeat('v', 121)]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('name')->getErrors());
    }
}
