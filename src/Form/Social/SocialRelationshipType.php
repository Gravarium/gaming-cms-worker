<?php

declare(strict_types=1);

namespace App\Form\Social;

use App\Entity\Social\SocialRelationship;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Positive;

/** @extends AbstractType<array<string, mixed>> */
final class SocialRelationshipType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('targetId', IntegerType::class, [
                'label' => 'Mitglied-ID',
                'constraints' => [new Positive()],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Beziehung',
                'choices' => [
                    'Freundschaft' => SocialRelationship::TYPE_FRIEND,
                    'Folgen' => SocialRelationship::TYPE_FOLLOW,
                ],
                'constraints' => [new Choice(choices: [SocialRelationship::TYPE_FRIEND, SocialRelationship::TYPE_FOLLOW])],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'social-relationship',
        ]);
    }
}
