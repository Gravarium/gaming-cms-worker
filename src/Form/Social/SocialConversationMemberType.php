<?php

declare(strict_types=1);

namespace App\Form\Social;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

/** @extends AbstractType<array<string, mixed>> */
final class SocialConversationMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('recipientIds', ChoiceType::class, [
            'label' => 'Mitglieder',
            'help' => 'Es erscheinen nur aktive Profile, deren Anzeigename für dich sichtbar ist. Die Social-Privatsphäre wird beim Hinzufügen erneut geprüft.',
            'choices' => $options['recipient_choices'],
            'multiple' => true,
            'expanded' => false,
            'required' => false,
            'constraints' => [
                new Count(
                    min: 0,
                    max: $options['max_recipients'],
                    maxMessage: 'Die Gruppe darf höchstens 20 aktive Mitglieder enthalten.',
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'social-conversation-members',
            'recipient_choices' => [],
            'max_recipients' => 19,
        ]);
        $resolver->setAllowedTypes('recipient_choices', 'array');
        $resolver->setAllowedTypes('max_recipients', 'int');
    }
}
