<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuildDiscordIntegration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

/** @extends AbstractType<GuildDiscordIntegration> */
final class GuildDiscordIntegrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('webhookUrl', PasswordType::class, [
                'label' => 'Discord-Webhook-Adresse',
                'mapped' => false,
                'required' => false,
                'always_empty' => true,
                'help' => $options['has_webhook'] ? 'Leer lassen, um die bereits verschlüsselt gespeicherte Adresse zu behalten.' : 'In Discord unter Integrationen einen Webhook anlegen und die Adresse hier einfügen.',
                'constraints' => [
                    new Url(protocols: ['https'], requireTld: true),
                    new Regex(pattern: '#^https://(?:canary\.|ptb\.)?(?:discord(?:app)?\.com)/api/webhooks/\d+/[A-Za-z0-9._-]+$#', message: 'Bitte eine gültige Discord-Webhook-Adresse verwenden.'),
                ],
            ])
            ->add('enabled', CheckboxType::class, ['label' => 'Discord-Benachrichtigungen aktivieren', 'required' => false])
            ->add('notifyEvents', CheckboxType::class, ['label' => 'Neue Termine und Raids senden', 'required' => false])
            ->add('notifyAnnouncements', CheckboxType::class, ['label' => 'Neue interne Mitteilungen senden', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GuildDiscordIntegration::class, 'has_webhook' => false]);
        $resolver->setAllowedTypes('has_webhook', 'bool');
    }
}
