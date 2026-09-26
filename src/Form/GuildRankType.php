<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuildRank;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<GuildRank> */
final class GuildRankType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Rangname',
                'constraints' => [new Length(max: 100)],
                'attr' => ['maxlength' => 100],
            ])
            ->add('color', null, ['label' => 'Farbe (z. B. #8b5cf6)', 'required' => false])
            ->add('position', IntegerType::class, ['label' => 'Sortierung'])
            ->add('permissions', ChoiceType::class, [
                'label' => 'Gildenrechte',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices' => [
                    'Mitglieder verwalten' => 'manage_members',
                    'Bewerbungen verwalten' => 'manage_applications',
                    'Termine verwalten' => 'manage_events',
                    'Gildeninhalte verwalten' => 'manage_content',
                ],
            ])
            ->add('defaultRank', CheckboxType::class, ['label' => 'Standardrang für neue Mitglieder', 'required' => false])
            ->add('enabled', CheckboxType::class, ['label' => 'Rang aktiv', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => GuildRank::class]); }
}
