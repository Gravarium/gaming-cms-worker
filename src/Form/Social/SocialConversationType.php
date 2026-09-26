<?php

declare(strict_types=1);

namespace App\Form\Social;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Positive;

/** @extends AbstractType<array<string, mixed>> */
final class SocialConversationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('recipientId', IntegerType::class, [
                'label' => 'Mitglied-ID',
                'constraints' => [new Positive()],
            ])
            ->add('title', TextType::class, [
                'label' => 'Gruppentitel (optional)',
                'required' => false,
                'constraints' => [new Length(max: 180)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'social-conversation',
        ]);
    }
}
