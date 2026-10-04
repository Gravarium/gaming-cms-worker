<?php

declare(strict_types=1);

namespace App\Form\Competition;

use App\Entity\Competition\CompetitionSeason;
use App\Entity\Game;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CompetitionSeason> */
final class CompetitionSeasonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, ['label' => 'Name'])
            ->add('game', EntityType::class, ['class' => Game::class, 'choice_label' => 'name', 'label' => 'Spiel'])
            ->add('startsAt', DateTimeType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable', 'label' => 'Beginn'])
            ->add('endsAt', DateTimeType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'label' => 'Ende']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CompetitionSeason::class]);
    }
}
