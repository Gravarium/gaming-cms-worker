<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuildApplication;
use App\Entity\GuildApplicationQuestion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<GuildApplication> */
final class GuildApplicationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('applicantName', null, ['label' => 'Dein Name'])
            ->add('email', EmailType::class, ['label' => 'E-Mail-Adresse'])
            ->add('characterName', null, ['label' => 'Charaktername'])
            ->add('characterClass', null, ['label' => 'Klasse oder Rolle', 'required' => false])
            ->add('message', TextareaType::class, ['label' => 'Deine Bewerbung', 'attr' => ['rows' => 9]]);

        foreach ($options['questions'] as $question) {
            $type = match ($question->getType()) {
                GuildApplicationQuestion::TYPE_TEXTAREA => TextareaType::class,
                GuildApplicationQuestion::TYPE_CHECKBOX => CheckboxType::class,
                default => null,
            };
            $fieldOptions = [
                'mapped' => false,
                'label' => $question->getLabel(),
                'help' => $question->getHelpText(),
                'required' => $question->isRequired(),
            ];
            if ($type === TextareaType::class) { $fieldOptions['attr'] = ['rows' => 5]; }
            if ($question->isRequired()) {
                $fieldOptions['constraints'] = $type === CheckboxType::class
                    ? [new IsTrue(message: 'Bitte bestätige dieses Feld.')]
                    : [new NotBlank(message: 'Bitte beantworte dieses Pflichtfeld.')];
            }
            $builder->add('question_'.$question->getId(), $type, $fieldOptions);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GuildApplication::class, 'questions' => []]);
        $resolver->setAllowedTypes('questions', 'array');
    }
}
