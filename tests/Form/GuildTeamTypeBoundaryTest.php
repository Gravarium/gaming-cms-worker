<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Guild;
use App\Entity\GuildTeam;
use App\Form\GuildTeamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildTeamTypeBoundaryTest extends KernelTestCase
{
    public function testExactEntityNameLimitHasNoFieldErrorAndIsRendered(): void
    {
        $form = $this->submitTeamName(str_repeat('t', 120));

        self::assertTrue($form->isSynchronized());
        self::assertCount(0, $form->get('name')->getErrors());
        self::assertSame(120, $form->get('name')->createView()->vars['attr']['maxlength']);
    }

    public function testNameAboveEntityLimitHasAFieldError(): void
    {
        $form = $this->submitTeamName(str_repeat('t', 121));

        self::assertTrue($form->isSynchronized());
        self::assertCount(1, $form->get('name')->getErrors());
    }

    private function submitTeamName(string $name): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $form = $formFactory->create(
            GuildTeamType::class,
            new GuildTeam(),
            ['csrf_protection' => false, 'guild' => new Guild()],
        );
        $form->submit(['name' => $name, 'members' => []]);

        return $form;
    }
}
