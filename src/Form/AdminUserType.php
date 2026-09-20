<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccessRole;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Security\CmsPermission;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<User> */
final class AdminUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [new Length(min: 12, minMessage: 'Das Passwort muss mindestens 12 Zeichen lang sein.')];
        if ($options['password_required']) { $passwordConstraints[] = new NotBlank(message: 'Bitte gib ein Passwort ein.'); }

        $builder
            ->add('displayName', TextType::class, ['label' => 'Anzeigename'])
            ->add('email', EmailType::class, ['label' => 'E-Mail-Adresse'])
            ->add('admin', CheckboxType::class, [
                'label' => 'Volladministrator (alle Rechte)',
                'required' => false,
                'disabled' => !$options['can_assign_admin'],
                'help' => $options['can_assign_admin'] ? null : 'Nur ein Volladministrator darf dieses Recht ändern.',
            ])
            ->add('permissions', ChoiceType::class, [
                'label' => 'Einzelne CMS-Berechtigungen',
                'help' => 'Für Mitarbeiter ohne Volladministrator-Recht. Mehrere Bereiche sind kombinierbar.',
                'choices' => CmsPermission::ASSIGNABLE,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('accessRoles', EntityType::class, [
                'label' => 'Zentrale Rollenprofile',
                'class' => AccessRole::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('active', CheckboxType::class, ['label' => 'Konto aktiv', 'required' => false])
            ->add('lockedUntil', DateTimeType::class, [
                'label' => 'Gesperrt bis',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'Leer lassen für keine zeitliche Sperre.',
            ])
            ->add('lockReason', null, ['label' => 'Sperrgrund', 'required' => false, 'attr' => ['maxlength' => 255]])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => $options['password_required'],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints' => $passwordConstraints,
                'first_options' => ['label' => $options['password_required'] ? 'Passwort' : 'Neues Passwort', 'help' => $options['password_required'] ? 'Mindestens 12 Zeichen.' : 'Leer lassen, um das bisherige Passwort zu behalten.'],
                'second_options' => ['label' => 'Passwort wiederholen'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class, 'password_required' => false, 'can_assign_admin' => false])
            ->setAllowedTypes('password_required', 'bool')
            ->setAllowedTypes('can_assign_admin', 'bool');
    }
}
