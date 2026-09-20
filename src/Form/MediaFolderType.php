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
        $builder->add('name', null, ['label' => 'Ordnername'])
            ->add('parent', EntityType::class, [
                'class' => MediaFolder::class,
                'choice_label' => 'pathLabel',
                'placeholder' => 'Hauptebene',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MediaFolder::class]);
    }
}
