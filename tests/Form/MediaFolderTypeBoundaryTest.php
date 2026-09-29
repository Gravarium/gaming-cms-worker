<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\MediaFolder;
use App\Form\MediaFolderType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class MediaFolderTypeBoundaryTest extends KernelTestCase
{
    public function testExactEntityNameLimitHasNoFieldErrorAndIsRendered(): void
    {
        $form = $this->submitMediaFolderName(str_repeat('m', 120));

        self::assertTrue($form->isSynchronized());
        self::assertCount(0, $form->get('name')->getErrors());
        self::assertSame(120, $form->createView()->children['name']->vars['attr']['maxlength']);
    }

    public function testNameAboveEntityLimitHasAFieldError(): void
    {
        $form = $this->submitMediaFolderName(str_repeat('m', 121));

        self::assertTrue($form->isSynchronized());
        self::assertCount(1, $form->get('name')->getErrors());
    }

    private function submitMediaFolderName(string $name): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $form = $formFactory->create(MediaFolderType::class, new MediaFolder(), ['csrf_protection' => false]);
        $form->submit(['name' => $name]);

        return $form;
    }
}
