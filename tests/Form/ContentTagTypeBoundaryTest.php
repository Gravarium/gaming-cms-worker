<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\ContentTag;
use App\Form\ContentTagType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class ContentTagTypeBoundaryTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testNameAcceptsTheExactColumnLimitAndRendersIt(): void
    {
        $form = $this->factory->create(ContentTagType::class, new ContentTag());
        $name = str_repeat('t', 100);
        $form->submit(['name' => $name]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame($name, $form->getData()->getName());
        self::assertSame(100, $form->createView()->children['name']->vars['attr']['maxlength']);
    }

    public function testNameLongerThanTheColumnLimitHasAFieldError(): void
    {
        $form = $this->factory->create(ContentTagType::class, new ContentTag());
        $form->submit(['name' => str_repeat('t', 101)]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('name')->getErrors());
    }
}
