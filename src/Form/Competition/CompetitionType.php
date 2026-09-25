<?php

declare(strict_types=1);

namespace App\Form\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionSeason;
use App\Entity\Game;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<Competition> */
final class CompetitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $lockStructure = $options['lock_structure'];
        $formatChoices = [];
        foreach (Competition::FORMATS as $format) {
            $formatChoices[$format] = $format;
        }
        $builder
            ->add('name', null, ['label' => 'Name'])
            ->add('game', EntityType::class, ['class' => Game::class, 'choice_label' => 'name', 'label' => 'Spiel', 'disabled' => $lockStructure])
            ->add('season', EntityType::class, ['class' => CompetitionSeason::class, 'choice_label' => 'name', 'required' => false, 'label' => 'Saison', 'disabled' => $lockStructure])
            ->add('description', TextareaType::class, ['required' => false, 'label' => 'Beschreibung'])
            ->add('format', ChoiceType::class, ['choices' => $formatChoices, 'label' => 'Format', 'disabled' => $lockStructure])
            ->add('mode', ChoiceType::class, ['choices' => ['Einzel' => Competition::MODE_SOLO, 'Team' => Competition::MODE_TEAM], 'label' => 'Teilnehmertyp', 'disabled' => $lockStructure])
            ->add('visibility', ChoiceType::class, ['choices' => ['Öffentlich' => Competition::VISIBILITY_PUBLIC, 'Privat' => Competition::VISIBILITY_PRIVATE], 'label' => 'Sichtbarkeit'])
            ->add('startsAt', DateTimeType::class, ['widget' => 'single_text', 'label' => 'Beginn', 'disabled' => $lockStructure])
            ->add('endsAt', DateTimeType::class, ['widget' => 'single_text', 'required' => false, 'label' => 'Ende', 'disabled' => $lockStructure])
            ->add('checkInDeadline', DateTimeType::class, ['widget' => 'single_text', 'required' => false, 'label' => 'Check-in bis', 'disabled' => $lockStructure])
            ->add('maxParticipants', IntegerType::class, ['required' => false, 'label' => 'Max. Teilnehmer', 'disabled' => $lockStructure])
            ->add('teamSize', IntegerType::class, ['label' => 'Teamgröße', 'disabled' => $lockStructure]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Competition::class, 'lock_structure' => false]);
        $resolver->setAllowedTypes('lock_structure', 'bool');
    }
}
