<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\AccessRole;
use App\Form\AccessRoleType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class AccessRoleTypeBoundaryTest extends KernelTestCase
{
    public function testExactStoredTextLimitsAreAcceptedAndRendered(): void
    {
        $limits = [
            'key' => 80,
            'name' => 120,
        ];
        $form = $this->submitRole([
            'key' => 'a' . str_repeat('b', $limits['key'] - 1),
            'name' => str_repeat('é', $limits['name']),
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertTrue($form->get('key')->getConfig()->getRequired());
        self::assertTrue($form->get('name')->getConfig()->getRequired());

        $view = $form->createView();
        foreach ($limits as $field => $limit) {
            self::assertSame($limit, $view->children[$field]->vars['attr']['maxlength']);
        }
    }

    public function testOneOverStoredTextLimitsAreRejectedByTheirFields(): void
    {
        $overLimitValues = [
            'key' => str_repeat('a', 81),
            'name' => str_repeat('é', 121),
        ];

        foreach ($overLimitValues as $field => $value) {
            $form = $this->submitRole([$field => $value]);

            self::assertTrue($form->isSynchronized(), sprintf('%s should remain synchronized.', $field));
            self::assertNotCount(
                0,
                $form->get($field)->getErrors(),
                sprintf('%s should reject a value over its stored limit.', $field),
            );
        }
    }

    public function testLockedKeyRemainsDisabled(): void
    {
        $form = $this->submitRole([], true);

        self::assertTrue($form->get('key')->getConfig()->getDisabled());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function submitRole(array $overrides, bool $keyLocked = false, ?AccessRole $role = null): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $builder = $formFactory->createBuilder(AccessRoleType::class, $role ?? new AccessRole(), [
            'key_locked' => $keyLocked,
            'csrf_protection' => false,
        ]);
        $form = $builder->getForm();
        $form->submit(array_replace([
            'key' => 'role-editor',
            'name' => 'Synthetic role',
            'description' => '',
            'permissions' => [],
            'active' => true,
        ], $overrides));

        return $form;
    }
}
