<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\VideoCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<VideoCategory> */
final class VideoCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Name',
                'constraints' => [new Length(max: 120)],
                'attr' => ['maxlength' => 120],
            ])
            ->add('description', TextareaType::class, ['label' => 'Beschreibung', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('enabled', CheckboxType::class, ['label' => 'Öffentlich sichtbar', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => VideoCategory::class]); }
}
