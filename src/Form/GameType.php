<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Game;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<Game> */
final class GameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Spielname',
                'constraints' => [new Length(max: 120)],
                'attr' => ['maxlength' => 120],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'required' => false,
                'attr' => ['rows' => 5, 'maxlength' => 1000],
                'constraints' => [new Length(max: 1000)],
            ])
            ->add('websiteUrl', UrlType::class, [
                'label' => 'Offizielle Website',
                'required' => false,
                'attr' => ['maxlength' => 500],
                'constraints' => [new Length(max: 500)],
            ])
            ->add('enabled', CheckboxType::class, ['label' => 'Öffentlich sichtbar', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => Game::class]); }
}
