<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\GuildApplication;
use App\Form\GuildApplicationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildApplicationTypeBoundaryTest extends KernelTestCase
{
    public function testAcceptsCoreFieldsAtPersistedLimits(): void
    {
        $form = $this->submitApplication([
            'applicantName' => str_repeat('é', 120),
            'email' => $this->emailAtLimit(50),
            'characterName' => str_repeat('界', 120),
            'characterClass' => str_repeat('x', 100),
            'message' => str_repeat('m', 5000),
        ]);

        self::assertTrue($form->isValid());
        foreach (['applicantName', 'email', 'characterName', 'characterClass', 'message'] as $field) {
            self::assertTrue($form->get($field)->isValid(), sprintf('Expected %s to be valid at its limit.', $field));
        }
    }

    public function testRejectsApplicantNameBeyondPersistedLimitAtFieldLevel(): void
    {
        $form = $this->submitApplication(['applicantName' => str_repeat('é', 121)]);

        self::assertFalse($form->get('applicantName')->isValid());
    }

    public function testRejectsEmailBeyondPersistedLimitAtFieldLevel(): void
    {
        $form = $this->submitApplication(['email' => $this->emailAtLimit(51)]);

        self::assertFalse($form->get('email')->isValid());
    }

    public function testRejectsCharacterNameBeyondPersistedLimitAtFieldLevel(): void
    {
        $form = $this->submitApplication(['characterName' => str_repeat('界', 121)]);

        self::assertFalse($form->get('characterName')->isValid());
    }

    public function testRejectsCharacterClassBeyondPersistedLimitAtFieldLevel(): void
    {
        $form = $this->submitApplication(['characterClass' => str_repeat('x', 101)]);

        self::assertFalse($form->get('characterClass')->isValid());
    }

    public function testRejectsMessageBeyondPersistedLimitAtFieldLevel(): void
    {
        $form = $this->submitApplication(['message' => str_repeat('m', 5001)]);

        self::assertFalse($form->get('message')->isValid());
    }

    public function testRejectsMessageBelowPersistedMinimumAtFieldLevel(): void
    {
        $form = $this->submitApplication(['message' => str_repeat('m', 19)]);

        self::assertFalse($form->get('message')->isValid());
    }

    public function testAllowsBlankOptionalCharacterClass(): void
    {
        $form = $this->submitApplication(['characterClass' => '']);

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('characterClass')->isValid());
    }

    public function testCoreFieldsAdvertiseTheirPersistedLengthLimits(): void
    {
        $form = $this->createApplicationForm();
        $view = $form->createView();

        self::assertSame(120, $view->children['applicantName']->vars['attr']['maxlength']);
        self::assertSame(180, $view->children['email']->vars['attr']['maxlength']);
        self::assertSame(120, $view->children['characterName']->vars['attr']['maxlength']);
        self::assertSame(100, $view->children['characterClass']->vars['attr']['maxlength']);
        self::assertSame(5000, $view->children['message']->vars['attr']['maxlength']);
        self::assertSame(20, $view->children['message']->vars['attr']['minlength']);
    }

    /** @param array<string, string> $overrides */
    private function submitApplication(array $overrides = []): FormInterface
    {
        $form = $this->createApplicationForm();
        $form->submit(array_replace([
            'applicantName' => 'Synthetic applicant',
            'email' => 'player@example.com',
            'characterName' => 'Synthetic character',
            'characterClass' => '',
            'message' => 'A sufficiently long application message.',
        ], $overrides));

        return $form;
    }

    private function createApplicationForm(): FormInterface
    {
        $formFactory = static::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        return $formFactory->create(GuildApplicationType::class, new GuildApplication(), [
            'csrf_protection' => false,
        ]);
    }

    private function emailAtLimit(int $secondDomainLabelLength): string
    {
        return str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', $secondDomainLabelLength).'.com';
    }
}
