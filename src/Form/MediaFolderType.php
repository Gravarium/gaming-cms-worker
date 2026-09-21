<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MediaFolder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<MediaFolder> */
final class MediaFolderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var MediaFolder|null $current */
        $current = $options['current_folder'];

        $builder->add('name', null, ['label' => 'Ordnername'])
            ->add('parent', EntityType::class, [
                'class' => MediaFolder::class,
                'choice_label' => 'pathLabel',
                'placeholder' => 'Hauptebene',
                'required' => false,
                'mapped' => false,
                'data' => $current?->getParent(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => MediaFolder::class, 'current_folder' => null])
            ->setAllowedTypes('current_folder', ['null', MediaFolder::class]);
    }
}
