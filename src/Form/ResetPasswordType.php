<?php

declare(strict_types=1);

namespace App\Form;

use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
            'constraints' => PasswordPolicy::constraints(),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'reset-password']);
    }
}
