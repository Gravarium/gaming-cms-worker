<?php

declare(strict_types=1);

namespace App\Form\Social;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class SocialMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextareaType::class, [
            'label' => 'Nachricht',
            'attr' => ['maxlength' => 5000, 'rows' => 5],
            'constraints' => [new NotBlank(), new Length(max: 5000)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'social-message',
        ]);
    }
}
