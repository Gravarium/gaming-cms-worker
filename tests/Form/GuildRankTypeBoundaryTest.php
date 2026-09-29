<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\GuildRank;
use App\Form\GuildRankType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildRankTypeBoundaryTest extends KernelTestCase
{
    public function testAcceptsNameAtPersistedUnicodeLimit(): void
    {
        $form = $this->submitRankName(str_repeat('é', 100));

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('name')->isValid());
    }

    public function testRejectsNameBeyondPersistedUnicodeLimitAtFieldLevel(): void
    {
        $form = $this->submitRankName(str_repeat('é', 101));

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('name')->isValid());
    }

    public function testNameInputAdvertisesPersistedMaximum(): void
    {
        $form = $this->createRankForm();
        $view = $form->createView();

        self::assertSame(100, $view->children['name']->vars['attr']['maxlength']);
    }

    public function testNameRemainsNonBlank(): void
    {
        $form = $this->submitRankName('');

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('name')->isValid());
    }

    private function submitRankName(string $name): FormInterface
    {
        $form = $this->createRankForm();
        $form->submit([
            'name' => $name,
            'color' => '',
            'position' => 0,
            'permissions' => [],
            'defaultRank' => false,
            'enabled' => true,
        ]);

        return $form;
    }

    private function createRankForm(): FormInterface
    {
        $formFactory = static::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        return $formFactory->create(GuildRankType::class, new GuildRank(), [
            'csrf_protection' => false,
        ]);
    }
}
