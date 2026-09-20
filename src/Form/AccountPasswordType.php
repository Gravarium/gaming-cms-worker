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
final class AccountPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $strongPassword = [
            new NotBlank(),
            new Length(min: 12, max: 4096, minMessage: 'Das neue Passwort muss mindestens {{ limit }} Zeichen lang sein.'),
            new Regex(pattern: '/\p{Ll}/u', message: 'Das neue Passwort braucht mindestens einen Kleinbuchstaben.'),
            new Regex(pattern: '/\p{Lu}/u', message: 'Das neue Passwort braucht mindestens einen Großbuchstaben.'),
            new Regex(pattern: '/\p{N}/u', message: 'Das neue Passwort braucht mindestens eine Zahl.'),
        ];

        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Bisheriges Passwort',
                'mapped' => false,
                'constraints' => [new NotBlank()],
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Die beiden neuen Passwörter stimmen nicht überein.',
                'first_options' => ['label' => 'Neues Passwort', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Neues Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
                'constraints' => $strongPassword,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'account-password-change']);
    }
}
