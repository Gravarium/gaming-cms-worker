<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Video;
use App\Form\VideoType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoTypeTitleBoundaryTest extends KernelTestCase
{
    public function testExactEntityTitleLimitHasNoFieldErrorAndIsRendered(): void
    {
        $form = $this->submitVideoTitle(str_repeat('v', 180));

        self::assertTrue($form->isSynchronized());
        self::assertCount(0, $form->get('title')->getErrors());
        self::assertSame(180, $form->createView()->children['title']->vars['attr']['maxlength']);
    }

    public function testTitleAboveEntityLimitHasAFieldError(): void
    {
        $form = $this->submitVideoTitle(str_repeat('v', 181));

        self::assertTrue($form->isSynchronized());
        self::assertCount(1, $form->get('title')->getErrors());
    }

    private function submitVideoTitle(string $title): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $form = $formFactory->create(VideoType::class, new Video(), ['csrf_protection' => false]);
        $form->submit([
            'title' => $title,
            'description' => 'Boundary test video',
            'sourceType' => Video::SOURCE_UPLOAD,
        ]);

        return $form;
    }
}
