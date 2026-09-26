<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MediaFolder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<array<string, mixed>> */
final class MediaAssetUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('moduleKey', ChoiceType::class, ['label' => 'Zielmodul', 'choices' => array_flip($options['modules'])])
            ->add('folder', EntityType::class, [
                'label' => 'Ordner',
                'class' => MediaFolder::class,
                'choice_label' => 'pathLabel',
                'placeholder' => 'Ohne Ordner',
                'required' => false,
            ])
            ->add('file', FileType::class, [
                'label' => 'Datei',
                'help' => 'Erlaubte Größe wird serverseitig je Zielmodul begrenzt: Branding/Gaming 5 MB, Standard 100 MB, Video 500 MB. Aktive Formate wie SVG/HTML/XML sind gesperrt.',
                'constraints' => [new File(
                    maxSize: '500M',
                    mimeTypes: [
                        'image/png', 'image/jpeg', 'image/webp', 'image/gif',
                        'image/x-icon', 'image/vnd.microsoft.icon',
                        'video/mp4', 'video/webm', 'video/quicktime',
                        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav',
                        'application/pdf', 'application/zip', 'application/x-zip-compressed',
                        'text/plain', 'text/csv', 'application/json',
                    ],
                    mimeTypesMessage: 'Dieser Dateityp ist aus Sicherheitsgründen nicht erlaubt.',
                )],
            ])
            ->add('title', null, [
                'label' => 'Titel',
                'required' => false,
                'attr' => ['maxlength' => 180],
                'constraints' => [new Length(max: 180)],
            ])
            ->add('altText', null, [
                'label' => 'Alternativtext',
                'required' => false,
                'attr' => ['maxlength' => 255],
                'constraints' => [new Length(max: 255)],
            ])
            ->add('caption', TextareaType::class, ['label' => 'Beschreibung', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('tags', null, ['label' => 'Schlagwörter', 'required' => false, 'help' => 'Mit Kommas trennen.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['modules' => []])->setAllowedTypes('modules', 'array');
    }
}
