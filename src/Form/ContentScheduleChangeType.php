<?php

declare(strict_types=1);

namespace App\Form;

use App\ContentSchedule\ContentScheduleChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ContentScheduleChange> */
final class ContentScheduleChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'Neuer Zeitpunkt',
                'required' => true,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Der neue Zeitpunkt muss in der Zukunft und innerhalb des Veröffentlichungsfensters liegen.',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Begründung',
                'required' => true,
                'trim' => true,
                'empty_data' => '',
                'attr' => ['rows' => 4, 'maxlength' => 300],
                'help' => 'Bitte gib eine kurze Begründung für die Terminänderung an.',
            ])
            ->add('expectedAt', HiddenType::class, [
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContentScheduleChange::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'content_schedule_change',
            'method' => 'POST',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'content_schedule_change';
    }
}
