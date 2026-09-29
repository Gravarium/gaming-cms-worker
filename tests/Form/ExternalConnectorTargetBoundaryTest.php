<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\ExternalConnectorTarget;
use App\Form\ExternalConnectorTargetType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class ExternalConnectorTargetBoundaryTest extends KernelTestCase
{
    public function testAcceptsExactPersistedFieldBoundaries(): void
    {
        $form = $this->submitTargetForm([
            'targetKey' => $this->targetKeyAtLimit(),
            'providerKey' => $this->providerKeyAtLimit(),
            'configurationReference' => 'c'.str_repeat('x', 119),
        ]);

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('targetKey')->isValid());
        self::assertTrue($form->get('providerKey')->isValid());
        self::assertTrue($form->get('configurationReference')->isValid());
    }

    public function testRejectsTargetKeyBeyondPersistedLengthAtFieldLevel(): void
    {
        $form = $this->submitTargetForm([
            'targetKey' => $this->targetKeyAtLimit().'a',
        ]);

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('targetKey')->isValid());
        self::assertTrue($form->get('providerKey')->isValid());
        self::assertTrue($form->get('configurationReference')->isValid());
    }

    public function testRejectsProviderKeyBeyondPersistedLengthAtFieldLevel(): void
    {
        $form = $this->submitTargetForm([
            'providerKey' => $this->providerKeyAtLimit().'a',
        ]);

        self::assertFalse($form->isValid());
        self::assertTrue($form->get('targetKey')->isValid());
        self::assertFalse($form->get('providerKey')->isValid());
        self::assertTrue($form->get('configurationReference')->isValid());
    }

    public function testRejectsConfigurationReferenceBeyondPersistedLengthAtFieldLevel(): void
    {
        $form = $this->submitTargetForm([
            'configurationReference' => 'c'.str_repeat('x', 120),
        ]);

        self::assertFalse($form->isValid());
        self::assertTrue($form->get('targetKey')->isValid());
        self::assertTrue($form->get('providerKey')->isValid());
        self::assertFalse($form->get('configurationReference')->isValid());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function submitTargetForm(array $overrides): FormInterface
    {
        $targetKey = $this->targetKeyAtLimit();
        $providerKey = $this->providerKeyAtLimit();
        $formFactory = static::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        $form = $formFactory->create(ExternalConnectorTargetType::class, new ExternalConnectorTarget(), [
            'csrf_protection' => false,
        ]);
        $form->submit(array_merge([
            'capability' => ExternalConnectorTarget::CAPABILITY_BACKUP,
            'targetKey' => $targetKey,
            'providerKey' => $providerKey,
            'displayName' => 'Synthetic connector target',
            'priority' => '100',
            'required' => '1',
            'enabled' => '0',
            'configurationReference' => 'connector.test',
        ], $overrides));

        return $form;
    }

    private function targetKeyAtLimit(): string
    {
        return 'a'.bin2hex(random_bytes(31)).'b';
    }

    private function providerKeyAtLimit(): string
    {
        return 'p'.bin2hex(random_bytes(31)).'q';
    }
}
