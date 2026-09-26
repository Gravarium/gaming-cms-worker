<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\AccountPasswordType;
use App\Form\ResetPasswordType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class PasswordPolicyFormBoundaryTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testResetPasswordFormAcceptsPolicyMaximumAndRendersBothLimits(): void
    {
        $password = $this->strongPasswordOfLength(4096);
        $form = $this->factory->create(ResetPasswordType::class);
        $form->submit(['password' => ['first' => $password, 'second' => $password]]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        $passwordView = $form->createView()->children['password'];
        self::assertSame(4096, $passwordView->children['first']->vars['attr']['maxlength']);
        self::assertSame(4096, $passwordView->children['second']->vars['attr']['maxlength']);
    }

    public function testResetPasswordFormRejectsValueAbovePolicyMaximum(): void
    {
        $password = $this->strongPasswordOfLength(4097);
        $form = $this->factory->create(ResetPasswordType::class);
        $form->submit(['password' => ['first' => $password, 'second' => $password]]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertNotCount(0, $form->get('password')->getErrors(true, true));
    }

    public function testAccountPasswordFormAcceptsPolicyMaximumAndRendersBothLimits(): void
    {
        $password = $this->strongPasswordOfLength(4096);
        $form = $this->factory->create(AccountPasswordType::class);
        $form->submit([
            'currentPassword' => 'Existing-Password-42',
            'newPassword' => ['first' => $password, 'second' => $password],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        $passwordView = $form->createView()->children['newPassword'];
        self::assertSame(4096, $passwordView->children['first']->vars['attr']['maxlength']);
        self::assertSame(4096, $passwordView->children['second']->vars['attr']['maxlength']);
    }

    public function testAccountPasswordFormRejectsValueAbovePolicyMaximum(): void
    {
        $password = $this->strongPasswordOfLength(4097);
        $form = $this->factory->create(AccountPasswordType::class);
        $form->submit([
            'currentPassword' => 'Existing-Password-42',
            'newPassword' => ['first' => $password, 'second' => $password],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertNotCount(0, $form->get('newPassword')->getErrors(true, true));
    }

    private function strongPasswordOfLength(int $length): string
    {
        return 'Aa1'.str_repeat('x', $length - 3);
    }
}
