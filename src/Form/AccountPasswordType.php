<?php

declare(strict_types=1);

namespace App\Form;

use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<null> */
final class AccountPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $strongPassword = PasswordPolicy::constraints();

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
