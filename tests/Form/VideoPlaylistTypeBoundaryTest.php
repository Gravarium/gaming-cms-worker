<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\VideoPlaylist;
use App\Form\VideoPlaylistType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class VideoPlaylistTypeBoundaryTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testTitleAcceptsTheExactPersistedColumnLength(): void
    {
        $form = $this->factory->create(VideoPlaylistType::class, new VideoPlaylist());
        $title = str_repeat('v', 160);
        $form->submit(['title' => $title]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame($title, $form->getData()->getTitle());
        self::assertSame(160, $form->createView()->children['title']->vars['attr']['maxlength']);
    }

    public function testTitleLongerThanTheColumnLengthHasAFieldError(): void
    {
        $form = $this->factory->create(VideoPlaylistType::class, new VideoPlaylist());
        $form->submit(['title' => str_repeat('v', 161)]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('title')->getErrors());
    }
}
