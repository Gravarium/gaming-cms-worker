<?php

declare(strict_types=1);

namespace App\Form\DownloadCatalogue;

use App\Entity\Download\DownloadPackage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<DownloadPackageInput> */
final class DownloadPackageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['create_mode']) {
            $builder
                ->add('title', TextType::class, [
                    'label' => 'Paketname',
                    'trim' => true,
                    'attr' => ['maxlength' => 180],
                ])
                ->add('slug', TextType::class, [
                    'label' => 'URL-Slug',
                    'trim' => true,
                    'help' => 'Kleinbuchstaben, Zahlen und einzelne Bindestriche verwenden.',
                    'attr' => [
                        'maxlength' => 200,
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*',
                        'autocomplete' => 'off',
                    ],
                ])
                ->add('type', ChoiceType::class, [
                    'label' => 'Pakettyp',
                    'choices' => [
                        'Datei' => 'file',
                        'Mod' => 'mod',
                        'Add-on' => 'addon',
                        'Modpack' => 'modpack',
                    ],
                ]);
        }

        $builder
            ->add('visibility', ChoiceType::class, [
                'label' => 'Sichtbarkeit',
                'choices' => [
                    'Öffentlich' => 'public',
                    'Mitglieder' => 'member',
                    'Administratoren' => 'admin',
                ],
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'Aktiv',
                'required' => false,
            ])
            ->add('save', SubmitType::class, [
                'label' => $options['create_mode'] ? 'Paket anlegen' : 'Änderungen speichern',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DownloadPackageInput::class,
            'create_mode' => true,
        ]);
        $resolver->setAllowedTypes('create_mode', 'bool');
    }
}
