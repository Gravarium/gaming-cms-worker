<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\GuildAnnouncement;
use App\Form\GuildAnnouncementType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildAnnouncementTypeBoundaryTest extends KernelTestCase
{
    public function testAcceptsTitleAtPersistedUnicodeLimit(): void
    {
        $form = $this->submitTitle(str_repeat('é', 180));

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('title')->isValid());
    }

    public function testRejectsTitleBeyondPersistedUnicodeLimitAtFieldLevel(): void
    {
        $form = $this->submitTitle(str_repeat('é', 181));

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('title')->isValid());
    }

    public function testTitleInputAdvertisesPersistedMaximum(): void
    {
        $form = $this->createAnnouncementForm();
        $view = $form->createView();

        self::assertSame(180, $view->children['title']->vars['attr']['maxlength']);
    }

    private function submitTitle(string $title): FormInterface
    {
        $form = $this->createAnnouncementForm();
        $form->submit([
            'title' => $title,
            'body' => 'Synthetic announcement body.',
            'pinned' => false,
        ]);

        return $form;
    }

    private function createAnnouncementForm(): FormInterface
    {
        $formFactory = static::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        return $formFactory->create(GuildAnnouncementType::class, new GuildAnnouncement(), [
            'csrf_protection' => false,
        ]);
    }
}
