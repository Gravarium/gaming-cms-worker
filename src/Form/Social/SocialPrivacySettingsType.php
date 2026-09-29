<?php

declare(strict_types=1);

namespace App\Form\Social;

use App\Entity\Social\SocialPrivacySettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SocialPrivacySettings> */
final class SocialPrivacySettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('messagePolicy', ChoiceType::class, [
                'label' => 'Wer darf mir schreiben?',
                'choices' => [
                    'Alle aktiven Mitglieder' => SocialPrivacySettings::MESSAGE_EVERYONE,
                    'Nur bestätigte Beziehungen' => SocialPrivacySettings::MESSAGE_RELATIONSHIPS,
                    'Niemand' => SocialPrivacySettings::MESSAGE_NOBODY,
                ],
            ])
            ->add('relationshipPolicy', ChoiceType::class, [
                'label' => 'Wer darf Beziehungen anfragen?',
                'choices' => [
                    'Alle aktiven Mitglieder' => SocialPrivacySettings::RELATIONSHIPS_EVERYONE,
                    'Niemand' => SocialPrivacySettings::RELATIONSHIPS_NOBODY,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SocialPrivacySettings::class,
            'csrf_token_id' => 'social-privacy',
        ]);
    }
}
