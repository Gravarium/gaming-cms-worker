<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ExternalConnectorTarget;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ExternalConnectorTarget> */
final class ExternalConnectorTargetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('capability', ChoiceType::class, [
                'label' => 'Fähigkeit',
                'choices' => [
                    'Backups' => ExternalConnectorTarget::CAPABILITY_BACKUP,
                    'Medien und Speicher' => ExternalConnectorTarget::CAPABILITY_MEDIA,
                    'E-Mail-Versand' => ExternalConnectorTarget::CAPABILITY_MAIL,
                    'Benachrichtigungen' => ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS,
                    'Externe Anmeldung' => ExternalConnectorTarget::CAPABILITY_IDENTITY,
                    'CDN' => ExternalConnectorTarget::CAPABILITY_CDN,
                    'Analyse' => ExternalConnectorTarget::CAPABILITY_ANALYTICS,
                ],
            ])
            ->add('targetKey', TextType::class, [
                'label' => 'Eindeutige Zielkennung',
                'help' => 'Zum Beispiel google-drive-1 oder backup-pcloud. Nach externer Einrichtung nicht mehr ändern.',
            ])
            ->add('providerKey', TextType::class, [
                'label' => 'Anbieterkennung',
                'help' => 'Freie, erweiterbare Kennung wie google-drive, pcloud, sftp oder eigener-server.',
            ])
            ->add('displayName', TextType::class, ['label' => 'Anzeigename'])
            ->add('priority', IntegerType::class, [
                'label' => 'Reihenfolge',
                'help' => 'Kleinere Zahlen werden zuerst verarbeitet.',
            ])
            ->add('required', CheckboxType::class, [
                'label' => 'Pflichtziel',
                'required' => false,
                'help' => 'Ein Fehler hält den Gesamtstatus auf Rot, verhindert aber nicht die anderen Ziele.',
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'Aktiv',
                'required' => false,
                'help' => 'Aktivieren, wenn die getrennte Server-Konfiguration bereitsteht.',
            ])
            ->add('configurationReference', TextType::class, [
                'label' => 'Server-Konfigurationsverweis',
                'required' => false,
                'help' => 'Nur eine nicht geheime Referenz, niemals Passwort, Token, URL oder Schlüssel eintragen.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ExternalConnectorTarget::class]);
    }
}
