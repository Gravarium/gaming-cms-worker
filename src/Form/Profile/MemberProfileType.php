<?php

declare(strict_types=1);

namespace App\Form\Profile;

use App\Entity\Profile\MemberProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Positive;

/** @extends AbstractType<MemberProfile> */
final class MemberProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $visibilityChoices = [
            'Öffentlich' => MemberProfile::VISIBILITY_PUBLIC,
            'Nur Mitglieder' => MemberProfile::VISIBILITY_MEMBERS,
            'Privat' => MemberProfile::VISIBILITY_PRIVATE,
        ];

        $builder
            ->add('bio', TextareaType::class, [
                'required' => false,
                'label' => 'Bio',
                'attr' => ['maxlength' => 2000, 'rows' => 6],
            ])
            ->add('avatarAssetId', IntegerType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Avatar-Medien-ID',
                'data' => $options['avatar_asset_id'],
                'constraints' => [new Positive()],
            ])
            ->add('bannerAssetId', IntegerType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Banner-Medien-ID',
                'data' => $options['banner_asset_id'],
                'constraints' => [new Positive()],
            ])
            ->add('displayNameVisibility', ChoiceType::class, ['label' => 'Anzeigename', 'choices' => $visibilityChoices])
            ->add('bioVisibility', ChoiceType::class, ['label' => 'Bio-Sichtbarkeit', 'choices' => $visibilityChoices])
            ->add('avatarVisibility', ChoiceType::class, ['label' => 'Avatar-Sichtbarkeit', 'choices' => $visibilityChoices])
            ->add('bannerVisibility', ChoiceType::class, ['label' => 'Banner-Sichtbarkeit', 'choices' => $visibilityChoices]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MemberProfile::class,
            'csrf_token_id' => 'member-profile-edit',
            'avatar_asset_id' => null,
            'banner_asset_id' => null,
        ]);
        $resolver->setAllowedTypes('avatar_asset_id', ['null', 'int']);
        $resolver->setAllowedTypes('banner_asset_id', ['null', 'int']);
    }
}
