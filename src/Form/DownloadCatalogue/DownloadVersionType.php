<?php

declare(strict_types=1);

namespace App\Form\DownloadCatalogue;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<DownloadVersionInput> */
final class DownloadVersionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('version', TextType::class, [
                'label' => 'Version',
                'trim' => true,
                'empty_data' => '',
                'attr' => ['maxlength' => 80],
            ])
            ->add('file', FileType::class, [
                'label' => 'Download-Datei',
                'required' => true,
            ])
            ->add('compatibility', TextareaType::class, [
                'label' => 'Kompatibilität',
                'required' => false,
                'empty_data' => '',
                'help' => 'Eine kompatible Spiel- oder Paketversion pro Zeile.',
                'attr' => ['rows' => 4, 'maxlength' => 2000],
            ])
            ->add('changelog', TextareaType::class, [
                'label' => 'Änderungsnotizen',
                'required' => false,
                'empty_data' => '',
                'help' => 'Optionale Versionshinweise für die öffentliche Paketansicht.',
                'attr' => ['rows' => 8, 'maxlength' => 12000],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Version sicher hochladen',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DownloadVersionInput::class,
            'csrf_protection' => false,
        ]);
    }
}
