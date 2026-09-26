<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\ForgotPasswordType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class ForgotPasswordTypeBoundaryTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testEmailAtTheUserLimitIsAcceptedAndRendered(): void
    {
        $email = $this->validEmailOfLength(180);
        self::assertSame(180, strlen($email));

        $form = $this->factory->create(ForgotPasswordType::class);
        $form->submit(['email' => $email]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertCount(0, $form->get('email')->getErrors());

        $attributes = $form->createView()->children['email']->vars['attr'];
        self::assertSame(180, $attributes['maxlength']);
        self::assertSame('email', $attributes['autocomplete']);
    }

    public function testEmailAboveTheUserLimitHasAFieldError(): void
    {
        $email = $this->validEmailOfLength(181);
        self::assertSame(181, strlen($email));

        $form = $this->factory->create(ForgotPasswordType::class);
        $form->submit(['email' => $email]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('email')->getErrors());
    }

    private function validEmailOfLength(int $length): string
    {
        return str_repeat('u', 64).'@'.str_repeat('d', 60).'.'.str_repeat('e', $length - 130).'.com';
    }
}
