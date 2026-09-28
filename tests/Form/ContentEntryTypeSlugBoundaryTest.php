<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\ContentEntry;
use App\Form\ContentEntryType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class ContentEntryTypeSlugBoundaryTest extends KernelTestCase
{
    public function testAcceptsSlugAtExistingGeneratedBaseLimit(): void
    {
        $form = $this->submitEntryForm($this->slugAtLimit());

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('slug')->isValid());
    }

    public function testRejectsSlugBeyondExistingGeneratedBaseLimitAtFieldLevel(): void
    {
        $form = $this->submitEntryForm($this->slugAtLimit().'c');

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('slug')->isValid());
    }

    private function submitEntryForm(string $slug): FormInterface
    {
        $formFactory = static::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        $form = $formFactory->create(ContentEntryType::class, new ContentEntry(), [
            'csrf_protection' => false,
        ]);
        $form->submit([
            'type' => ContentEntry::TYPE_NEWS,
            'title' => 'Synthetic slug boundary entry',
            'slug' => $slug,
            'body' => 'Synthetic content for a slug boundary test.',
            'status' => ContentEntry::STATUS_DRAFT,
        ]);

        return $form;
    }

    private function slugAtLimit(): string
    {
        return 'a'.bin2hex(random_bytes(89)).'b';
    }
}
