<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Game;
use App\Entity\Guild;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Url;

/** @extends AbstractType<Guild> */
final class GuildType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('game', EntityType::class, ['class' => Game::class, 'label' => 'Spiel', 'choice_label' => 'name'])
            ->add('name', null, ['label' => 'Gilden- oder Clanname'])
            ->add('serverName', null, ['label' => 'Server'])
            ->add('region', null, ['label' => 'Region', 'required' => false, 'attr' => ['placeholder' => 'z. B. Europa']])
            ->add('faction', null, ['label' => 'Fraktion', 'required' => false])
            ->add('description', TextareaType::class, ['label' => 'Beschreibung', 'attr' => ['rows' => 8]])
            ->add('websiteUrl', UrlType::class, ['label' => 'Externe Website', 'required' => false])
            ->add('logoFile', FileType::class, [
                'label' => 'Gildenlogo hochladen',
                'mapped' => false,
                'required' => false,
                'help' => 'Das gewählte Speicherziel des Gaming-Moduls wird automatisch verwendet.',
                'constraints' => [new Image(maxSize: '5M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp'])],
            ])
            ->add('logoUrl', UrlType::class, [
                'label' => 'Alternativ: vorhandene externe Logo-URL',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Url(requireTld: true)],
            ])
            ->add('recruitmentOpen', CheckboxType::class, ['label' => 'Nimmt neue Mitglieder auf', 'required' => false])
            ->add('enabled', CheckboxType::class, ['label' => 'Öffentlich sichtbar', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Guild::class]);
    }
}
