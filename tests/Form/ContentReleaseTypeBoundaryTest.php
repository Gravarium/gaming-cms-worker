<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\ContentRelease;
use App\Form\ContentReleaseType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class ContentReleaseTypeBoundaryTest extends KernelTestCase
{
    public function testExactEntityNameLimitHasNoFieldErrorAndIsRendered(): void
    {
        $form = $this->submitReleaseName(str_repeat('r', 160));

        self::assertTrue($form->isSynchronized());
        self::assertCount(0, $form->get('name')->getErrors());
        self::assertSame(160, $form->createView()->children['name']->vars['attr']['maxlength']);
    }

    public function testNameAboveEntityLimitHasAFieldError(): void
    {
        $form = $this->submitReleaseName(str_repeat('r', 161));

        self::assertTrue($form->isSynchronized());
        self::assertCount(1, $form->get('name')->getErrors());
    }

    private function submitReleaseName(string $name): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $form = $formFactory->create(ContentReleaseType::class, new ContentRelease(), ['csrf_protection' => false]);
        $form->submit([
            'name' => $name,
            'entries' => [],
            'status' => ContentRelease::STATUS_DRAFT,
        ]);

        return $form;
    }
}
