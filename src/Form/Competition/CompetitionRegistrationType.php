<?php

declare(strict_types=1);

namespace App\Form\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CompetitionParticipant> */
final class CompetitionRegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $mode = $options['competition_mode'];
        $choices = match ($mode) {
            Competition::MODE_SOLO => ['Einzel' => CompetitionParticipant::KIND_SOLO],
            Competition::MODE_TEAM => ['Team' => CompetitionParticipant::KIND_TEAM],
            default => ['Einzel' => CompetitionParticipant::KIND_SOLO, 'Team' => CompetitionParticipant::KIND_TEAM],
        };
        $builder
            ->add('name', null, ['label' => 'Teilnehmer- oder Teamname'])
            ->add('kind', ChoiceType::class, ['choices' => $choices, 'label' => 'Anmeldung als']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CompetitionParticipant::class, 'competition_mode' => null]);
        $resolver->setAllowedTypes('competition_mode', ['null', 'string']);
    }
}
