<?php

declare(strict_types=1);

namespace App\Form\Invitation;

use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<null> */
final class InvitationRegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', null, [
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 80)],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'first_options' => ['label' => 'Passwort', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
                'constraints' => PasswordPolicy::constraints(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'member-invitation-register']);
    }
}
