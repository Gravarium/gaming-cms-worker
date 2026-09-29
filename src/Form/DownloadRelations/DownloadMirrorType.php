<?php

declare(strict_types=1);

namespace App\Form\DownloadRelations;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<DownloadMirrorInput> */
final class DownloadMirrorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', TextType::class, [
                'label' => 'HTTPS-Mirror-URL',
                'trim' => true,
                'empty_data' => '',
                'attr' => ['maxlength' => 500, 'inputmode' => 'url'],
            ])
            ->add('trusted', CheckboxType::class, [
                'label' => 'Als geprüft und öffentlich nutzbar markieren',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, ['label' => 'Mirror speichern']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DownloadMirrorInput::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'download-mirror-create',
        ]);
    }
}
