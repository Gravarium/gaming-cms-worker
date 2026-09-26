<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\GuildDiscordIntegration;
use App\Form\GuildDiscordIntegrationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class GuildDiscordIntegrationTypeBoundaryTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testWebhookAtTheExistingPolicyLimitIsAcceptedAndRendered(): void
    {
        $webhookUrl = $this->validWebhookUrlOfLength(1000);
        self::assertSame(1000, strlen($webhookUrl));

        $form = $this->factory->create(GuildDiscordIntegrationType::class, new GuildDiscordIntegration());
        $form->submit(['webhookUrl' => $webhookUrl]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertCount(0, $form->get('webhookUrl')->getErrors());
        self::assertSame(1000, $form->createView()->children['webhookUrl']->vars['attr']['maxlength']);
    }

    public function testWebhookAboveTheExistingPolicyLimitIsRejected(): void
    {
        $webhookUrl = $this->validWebhookUrlOfLength(1001);
        self::assertSame(1001, strlen($webhookUrl));

        $form = $this->factory->create(GuildDiscordIntegrationType::class, new GuildDiscordIntegration());
        $form->submit(['webhookUrl' => $webhookUrl]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('webhookUrl')->getErrors());
    }

    public function testStoredEncryptedWebhookIsNeverPrefilledIntoThePasswordField(): void
    {
        $integration = (new GuildDiscordIntegration())->setEncryptedWebhookUrl('encrypted-secret-ciphertext');
        $form = $this->factory->create(GuildDiscordIntegrationType::class, $integration, ['has_webhook' => true]);
        $field = $form->get('webhookUrl');
        $view = $form->createView()->children['webhookUrl'];

        self::assertFalse($field->getConfig()->getMapped());
        self::assertTrue($field->getConfig()->getOption('always_empty'));
        self::assertSame('', $view->vars['value']);
    }

    private function validWebhookUrlOfLength(int $length): string
    {
        $prefix = 'https://discord.com/api/webhooks/1/';

        return $prefix.str_repeat('t', $length - strlen($prefix));
    }
}
