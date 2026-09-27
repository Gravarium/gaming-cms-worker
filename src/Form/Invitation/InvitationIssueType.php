<?php

declare(strict_types=1);

namespace App\Form\Invitation;

use App\Entity\AccessRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/** @extends AbstractType<null> */
final class InvitationIssueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'mapped' => false,
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('accessRole', EntityType::class, [
                'mapped' => false,
                'class' => AccessRole::class,
                'choice_label' => 'name',
                'placeholder' => 'Keine zusätzliche Rolle',
                'required' => false,
            ])
            ->add('ttlHours', IntegerType::class, [
                'mapped' => false,
                'data' => 24,
                'constraints' => [new Range(min: 1, max: 168)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'member-invitation-issue']);
    }
}
