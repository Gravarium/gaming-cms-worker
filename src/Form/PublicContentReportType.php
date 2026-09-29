<?php

declare(strict_types=1);

namespace App\Form;

use App\Community\Interaction\ReportRecord;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array{reason?: string, details?: string}> */
final class PublicContentReportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', ChoiceType::class, [
                'label' => 'Grund',
                'placeholder' => 'Grund auswählen',
                'choices' => [
                    'Spam' => 'spam',
                    'Beleidigung oder Belästigung' => 'harassment',
                    'Missbrauch' => 'abuse',
                    'Illegale Inhalte' => 'illegal',
                    'Verletzung der Privatsphäre' => 'privacy',
                    'Sonstiges' => 'other',
                ],
                'constraints' => [new Assert\Choice(choices: ReportRecord::REASONS)],
            ])
            ->add('details', TextareaType::class, [
                'label' => 'Weitere Angaben',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 2000],
                'constraints' => [new Assert\Length(max: 2000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
