<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/** @extends AbstractType<null> */
final class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'invalid_message' => 'Die beiden Passwörter stimmen nicht überein.',
            'first_options' => ['label' => 'Neues Passwort', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Neues Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => [
                new NotBlank(),
                new Length(min: 12, max: 4096, minMessage: 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.'),
                new Regex(pattern: '/\p{Ll}/u', message: 'Das Passwort braucht mindestens einen Kleinbuchstaben.'),
                new Regex(pattern: '/\p{Lu}/u', message: 'Das Passwort braucht mindestens einen Großbuchstaben.'),
                new Regex(pattern: '/\p{N}/u', message: 'Das Passwort braucht mindestens eine Zahl.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'reset-password']);
    }
}
