<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MediaFolder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;

/** @extends AbstractType<array<string, mixed>> */
final class MediaAssetBatchUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('moduleKey', ChoiceType::class, [
                'label' => 'Zielmodul',
                'choices' => array_flip($options['modules']),
            ])
            ->add('folder', EntityType::class, [
                'label' => 'Ordner',
                'class' => MediaFolder::class,
                'choice_label' => 'pathLabel',
                'placeholder' => 'Ohne Ordner',
                'required' => false,
            ])
            ->add('files', FileType::class, [
                'label' => 'Dateien',
                'help' => 'Bis zu 10 Bilder oder Dokumente mit jeweils höchstens 25 MB. Branding und Gaming haben zusätzlich strengere Modulgrenzen. Jede Datei wird serverseitig geprüft und auf Schadsoftware untersucht.',
                'multiple' => true,
                'required' => true,
                'attr' => [
                    'accept' => '.png,.jpg,.jpeg,.webp,.gif,.ico,.pdf,.zip,.txt,.csv,.json',
                ],
                'constraints' => [
                    new Count(
                        min: 1,
                        max: 10,
                        minMessage: 'Wähle mindestens eine Datei aus.',
                        maxMessage: 'Pro Upload sind höchstens 10 Dateien erlaubt.',
                    ),
                    new All([
                        new File(
                            maxSize: '25M',
                            maxSizeMessage: 'Jede Datei darf höchstens 25 MB groß sein.',
                            mimeTypes: [
                                'image/png',
                                'image/jpeg',
                                'image/webp',
                                'image/gif',
                                'image/x-icon',
                                'image/vnd.microsoft.icon',
                                'application/pdf',
                                'application/zip',
                                'application/x-zip-compressed',
                                'text/plain',
                                'text/csv',
                                'application/json',
                            ],
                            mimeTypesMessage: 'Dieser Dateiinhalt ist für den Mehrfachupload nicht erlaubt.',
                        ),
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['modules' => []])
            ->setAllowedTypes('modules', 'array');
    }
}
