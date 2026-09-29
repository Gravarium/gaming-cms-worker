<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ModuleStorageSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ModuleStorageSetting> */
final class ModuleStorageSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('storageMode', ChoiceType::class, [
                'label' => 'Speicherziel',
                'choices' => [
                    'Intern auf dem CMS-Server' => ModuleStorageSetting::MODE_INTERNAL,
                    'Extern auf S3-kompatiblem Speicher' => ModuleStorageSetting::MODE_EXTERNAL,
                ],
            ])
            ->add('externalBaseUrl', UrlType::class, [
                'label' => 'Öffentliche Basisadresse für dieses Modul',
                'required' => false,
                'attr' => ['maxlength' => 500],
                'help' => 'Optional. Überschreibt die zentrale öffentliche S3-Adresse, z. B. https://media.example.de/gaming.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ModuleStorageSetting::class]);
    }
}
