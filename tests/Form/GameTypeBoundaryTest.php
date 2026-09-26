<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Game;
use App\Form\GameType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class GameTypeBoundaryTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testExactEntityBoundariesAreAcceptedAndRendered(): void
    {
        $form = $this->factory->create(GameType::class, new Game());
        $name = str_repeat('g', 120);
        $description = str_repeat('d', 1000);
        $websiteUrl = 'https://example.com/'.str_repeat('w', 480);
        $form->submit([
            'name' => $name,
            'description' => $description,
            'websiteUrl' => $websiteUrl,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame($name, $form->getData()->getName());
        self::assertSame($description, $form->getData()->getDescription());
        self::assertSame($websiteUrl, $form->getData()->getWebsiteUrl());

        $view = $form->createView();
        self::assertSame(120, $view->children['name']->vars['attr']['maxlength']);
        self::assertSame(1000, $view->children['description']->vars['attr']['maxlength']);
        self::assertSame(500, $view->children['websiteUrl']->vars['attr']['maxlength']);
    }

    public function testOverlongNameHasAFieldError(): void
    {
        $form = $this->factory->create(GameType::class, new Game());
        $form->submit(['name' => str_repeat('g', 121)]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('name')->getErrors());
    }

    public function testOverlongDescriptionHasAFieldError(): void
    {
        $form = $this->factory->create(GameType::class, new Game());
        $form->submit(['name' => 'Example game', 'description' => str_repeat('d', 1001)]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('description')->getErrors());
    }

    public function testOverlongWebsiteUrlHasAFieldError(): void
    {
        $form = $this->factory->create(GameType::class, new Game());
        $form->submit([
            'name' => 'Example game',
            'websiteUrl' => 'https://example.com/'.str_repeat('w', 481),
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('websiteUrl')->getErrors());
    }
}
