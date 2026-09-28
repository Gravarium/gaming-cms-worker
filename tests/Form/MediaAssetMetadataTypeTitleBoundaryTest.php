<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\MediaAsset;
use App\Form\MediaAssetMetadataType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class MediaAssetMetadataTypeTitleBoundaryTest extends KernelTestCase
{
    public function testExactEntityTitleLimitHasNoFieldErrorAndIsRendered(): void
    {
        $form = $this->submitAssetTitle(str_repeat('a', 180));

        self::assertTrue($form->isSynchronized());
        self::assertCount(0, $form->get('title')->getErrors());
        self::assertSame(180, $form->createView()->children['title']->vars['attr']['maxlength']);
    }

    public function testTitleAboveEntityLimitHasAFieldError(): void
    {
        $form = $this->submitAssetTitle(str_repeat('a', 181));

        self::assertTrue($form->isSynchronized());
        self::assertCount(1, $form->get('title')->getErrors());
    }

    private function submitAssetTitle(string $title): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $form = $formFactory->create(MediaAssetMetadataType::class, new MediaAsset(), ['csrf_protection' => false]);
        $form->submit(['title' => $title]);

        return $form;
    }
}
