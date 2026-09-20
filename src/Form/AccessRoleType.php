<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccessRole;
use App\Security\CmsPermission;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<AccessRole> */
final class AccessRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('key', null, ['label' => 'Technischer Schlüssel', 'disabled' => $options['key_locked'], 'help' => 'Kleinbuchstaben, Zahlen, Bindestrich oder Unterstrich.'])
            ->add('name', null, ['label' => 'Name'])
            ->add('description', TextareaType::class, ['label' => 'Beschreibung', 'required' => false])
            ->add('permissions', ChoiceType::class, ['label' => 'Enthaltene Berechtigungen', 'choices' => CmsPermission::ASSIGNABLE, 'multiple' => true, 'expanded' => true])
            ->add('active', CheckboxType::class, ['label' => 'Rolle aktiv', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AccessRole::class, 'key_locked' => false])->setAllowedTypes('key_locked', 'bool');
    }
}
