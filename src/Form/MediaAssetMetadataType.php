<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<MediaAsset> */
final class MediaAssetMetadataType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', null, ['label' => 'Titel'])
            ->add('altText', null, ['label' => 'Alternativtext', 'required' => false])
            ->add('caption', TextareaType::class, ['label' => 'Beschreibung', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('tagsText', null, ['label' => 'Schlagwörter', 'required' => false, 'help' => 'Mit Kommas trennen, maximal 30 Schlagwörter.'])
            ->add('folder', EntityType::class, [
                'class' => MediaFolder::class,
                'choice_label' => 'pathLabel',
                'placeholder' => 'Ohne Ordner',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MediaAsset::class]);
    }
}
