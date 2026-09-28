<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Category;
use App\Form\CategoryType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class CategoryTypeBoundaryTest extends KernelTestCase
{
    public function testExactNameLimitIsAcceptedAndRendered(): void
    {
        $form = $this->submitCategoryName(str_repeat('c', 100));

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertCount(0, $form->get('name')->getErrors());

        $view = $form->createView();
        self::assertSame(100, $view->children['name']->vars['attr']['maxlength']);
        self::assertArrayHasKey('parent', $view->children);
    }

    public function testNameAboveLimitHasANameFieldError(): void
    {
        $form = $this->submitCategoryName(str_repeat('c', 101));

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('name')->getErrors());
    }

    private function submitCategoryName(string $name): FormInterface
    {
        self::bootKernel();

        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $category = (new Category())->setSlug('category-boundary-'.bin2hex(random_bytes(8)));
        $form = $formFactory->create(CategoryType::class, $category, ['csrf_protection' => false]);
        $form->submit(['name' => $name, 'parent' => '', 'description' => '']);

        return $form;
    }
}
