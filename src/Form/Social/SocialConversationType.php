<?php

declare(strict_types=1);

namespace App\Form\Social;

use App\Social\SocialRateLimitPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<array<string, mixed>> */
final class SocialConversationType extends AbstractType
{
    /**
     * @param array<string, int> $recipientChoices
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('recipientIds', ChoiceType::class, [
                'label' => 'Mitglieder',
                'help' => 'Es erscheinen nur aktive Profile, deren Anzeigename für dich sichtbar ist. Die Social-Privatsphäre eines Mitglieds kann das Senden zusätzlich einschränken.',
                'choices' => $options['recipient_choices'],
                'multiple' => true,
                'expanded' => false,
                'required' => true,
                'constraints' => [
                    new Count(
                        min: 1,
                        max: SocialRateLimitPolicy::MAX_GROUP_PARTICIPANTS - 1,
                        minMessage: 'Wähle mindestens ein Mitglied aus.',
                        maxMessage: 'Eine Unterhaltung kann höchstens 20 Personen einschließlich dir enthalten.',
                    ),
                ],
            ])
            ->add('title', TextType::class, [
                'label' => 'Gruppentitel (optional)',
                'help' => 'Ohne Titel wird eine Direktnachricht an genau ein Mitglied erstellt. Für mehrere Mitglieder ist ein Gruppentitel erforderlich.',
                'required' => false,
                'constraints' => [new Length(max: 180)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'social-conversation',
            'recipient_choices' => [],
        ]);
        $resolver->setAllowedTypes('recipient_choices', 'array');
    }
}
