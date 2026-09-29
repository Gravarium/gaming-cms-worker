<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array{action?: string, reason?: string}> */
final class ContentModerationDecisionType extends AbstractType
{
    private const ACTION_LABELS = [
        'uphold' => 'Bericht bestätigen und Kommentar ausblenden',
        'reject' => 'Bericht ablehnen',
        'restore' => 'Kommentar wiederherstellen',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['actions'] as $action) {
            if (isset(self::ACTION_LABELS[$action])) {
                $choices[self::ACTION_LABELS[$action]] = $action;
            }
        }
        if ($choices === []) {
            throw new \InvalidArgumentException('At least one supported moderation action is required.');
        }

        $builder
            ->add('action', ChoiceType::class, [
                'label' => 'Entscheidung',
                'choices' => $choices,
                'constraints' => [new Assert\Choice(choices: array_values($choices))],
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Begründung',
                'attr' => ['rows' => 3, 'maxlength' => 500],
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte gib eine Begründung an.'),
                    new Assert\Length(max: 500),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'actions' => ['uphold', 'reject']]);
        $resolver->setAllowedTypes('actions', 'array');
    }
}
