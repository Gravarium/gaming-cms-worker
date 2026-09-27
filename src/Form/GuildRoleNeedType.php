<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/** @extends AbstractType<GuildRoleNeedInput> */
final class GuildRoleNeedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['identity_locked']) {
            $builder
                ->add('roleKey', TextType::class, [
                    'label' => 'Rolle',
                    'attr' => ['maxlength' => 40],
                    'constraints' => [new NotBlank(message: 'Bitte gib eine Rolle an.'), new Length(max: 40)],
                ])
                ->add('classKey', TextType::class, [
                    'label' => 'Klasse',
                    'attr' => ['maxlength' => 100],
                    'constraints' => [new NotBlank(message: 'Bitte gib eine Klasse an.'), new Length(max: 100)],
                ]);
        }

        $builder
            ->add('desiredCount', IntegerType::class, [
                'label' => 'Gesuchte Anzahl',
                'attr' => ['min' => 0, 'max' => 1000],
                'constraints' => [new Range(min: 0, max: 1000, notInRangeMessage: 'Die gesuchte Anzahl muss zwischen {{ min }} und {{ max }} liegen.')],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Aktiv und öffentlich anzeigen',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuildRoleNeedInput::class,
            'identity_locked' => false,
        ]);
        $resolver->setAllowedTypes('identity_locked', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'guild_role_need';
    }
}
