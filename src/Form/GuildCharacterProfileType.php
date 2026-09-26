<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuildMember;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GuildMember> */
final class GuildCharacterProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('characterName', TextType::class, [
                'label' => 'Charaktername',
                'attr' => ['maxlength' => 120],
            ])
            ->add('characterClass', TextType::class, [
                'label' => 'Klasse',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Änderungen speichern',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuildMember::class,
            'csrf_protection' => true,
        ]);
    }
}
