<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\User;
use App\Form\AdminUserType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class AdminUserTypeDisplayNameBoundaryTest extends KernelTestCase
{
    public function testExactDisplayNameLimitIsAcceptedAndRendered(): void
    {
        $form = $this->submitDisplayName(str_repeat('u', 80));

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertCount(0, $form->get('displayName')->getErrors());

        $view = $form->createView();
        self::assertSame(80, $view->children['displayName']->vars['attr']['maxlength']);
    }

    public function testDisplayNameAboveLimitHasAFieldError(): void
    {
        $form = $this->submitDisplayName(str_repeat('u', 81));

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('displayName')->getErrors());
    }

    private function submitDisplayName(string $displayName): FormInterface
    {
        self::bootKernel();

        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $user = $formFactory->create(AdminUserType::class, new User(), ['csrf_protection' => false]);
        $email = 'display-name-boundary-'.bin2hex(random_bytes(8)).'@example.invalid';
        $user->submit([
            'displayName' => $displayName,
            'email' => $email,
            'permissions' => [],
            'accessRoles' => [],
            'lockedUntil' => '',
            'lockReason' => '',
            'plainPassword' => ['first' => '', 'second' => ''],
        ]);

        return $user;
    }
}
