<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array{body?: string, parentId?: string}> */
final class PublicContentCommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('parentId', HiddenType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Dein Kommentar',
                'attr' => ['rows' => 5, 'maxlength' => 10000],
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte schreibe einen Kommentar.'),
                    new Assert\Length(max: 10000, maxMessage: 'Der Kommentar darf höchstens {{ limit }} Zeichen lang sein.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
