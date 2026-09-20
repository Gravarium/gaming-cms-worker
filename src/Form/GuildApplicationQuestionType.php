<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuildApplicationQuestion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GuildApplicationQuestion> */
final class GuildApplicationQuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', null, ['label' => 'Frage'])
            ->add('helpText', TextareaType::class, ['label' => 'Hilfetext', 'required' => false])
            ->add('type', ChoiceType::class, ['label' => 'Antworttyp', 'choices' => [
                'Kurzer Text' => GuildApplicationQuestion::TYPE_TEXT,
                'Langer Text' => GuildApplicationQuestion::TYPE_TEXTAREA,
                'Bestätigung (Ja/Nein)' => GuildApplicationQuestion::TYPE_CHECKBOX,
            ]])
            ->add('position', IntegerType::class, ['label' => 'Sortierung'])
            ->add('required', CheckboxType::class, ['label' => 'Pflichtfrage', 'required' => false])
            ->add('enabled', CheckboxType::class, ['label' => 'Frage aktiv', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => GuildApplicationQuestion::class]); }
}
