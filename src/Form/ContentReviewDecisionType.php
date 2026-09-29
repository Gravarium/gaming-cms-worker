<?php

declare(strict_types=1);

namespace App\Form;

use App\ContentReview\ReviewDecision;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ReviewDecision> */
final class ContentReviewDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('decision', ChoiceType::class, [
                'label' => 'Entscheidung',
                'placeholder' => 'Bitte auswählen',
                'choices' => [
                    'Sofort veröffentlichen' => ReviewDecision::ACTION_PUBLISH,
                    'Veröffentlichung planen' => ReviewDecision::ACTION_SCHEDULE,
                    'Änderungen anfordern' => ReviewDecision::ACTION_REQUEST_CHANGES,
                ],
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Begründung',
                'required' => true,
                'trim' => true,
                'attr' => ['rows' => 4, 'maxlength' => 300],
                'help' => 'Bitte gib eine kurze Begründung für die Entscheidung an.',
            ])
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'Veröffentlichungszeitpunkt',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Nur für „Veröffentlichung planen“ erforderlich.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReviewDecision::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'content_review_decision',
            'method' => 'POST',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'content_review_decision';
    }
}
