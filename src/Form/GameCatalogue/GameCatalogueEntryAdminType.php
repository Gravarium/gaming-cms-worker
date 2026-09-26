<?php

declare(strict_types=1);

namespace App\Form\GameCatalogue;

use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\Entity\GameCatalogue\GamePublisher;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<GameCatalogueEntry> */
final class GameCatalogueEntryAdminType extends AbstractType
{
    /**
     * @param FormBuilderInterface<GameCatalogueEntry> $builder
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['include_game']) {
            $builder->add('gameChoice', EntityType::class, [
                'label' => 'Spiel',
                'class' => Game::class,
                'choices' => $options['available_games'],
                'choice_label' => 'name',
                'placeholder' => 'Spiel auswählen',
                'mapped' => false,
                'data' => $options['selected_game'],
                'invalid_message' => 'Dieses Spiel ist nicht verfügbar oder besitzt bereits einen Game Hub.',
            ]);
        }

        $builder
            ->add('publisher', EntityType::class, [
                'label' => 'Publisher',
                'class' => GamePublisher::class,
                'choices' => $options['publishers'],
                'choice_label' => 'name',
                'placeholder' => 'Publisher offen',
                'required' => false,
            ])
            ->add('developer', TextType::class, [
                'label' => 'Entwickler',
                'required' => false,
                'constraints' => [new Length(max: 160, maxMessage: 'Der Entwicklername darf höchstens {{ limit }} Zeichen enthalten.')],
                'attr' => ['maxlength' => 160],
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'Kurzbeschreibung',
                'required' => false,
                'constraints' => [new Length(max: 5000, maxMessage: 'Die Kurzbeschreibung darf höchstens {{ limit }} Zeichen enthalten.')],
                'attr' => ['rows' => 6, 'maxlength' => 5000],
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'Game Hub öffentlich anzeigen',
                'required' => false,
            ])
            ->add('genres', EntityType::class, [
                'label' => 'Genres',
                'class' => GameGenre::class,
                'choices' => $options['genres'],
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'mapped' => false,
                'data' => $options['selected_genres'],
                'help' => 'Die vorhandenen Genres werden angezeigt. Die Pflege der Genre-Liste erfolgt separat.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GameCatalogueEntry::class,
            'include_game' => true,
            'available_games' => [],
            'selected_game' => null,
            'publishers' => [],
            'genres' => [],
            'selected_genres' => [],
        ]);
        $resolver->setAllowedTypes('include_game', 'bool');
        $resolver->setAllowedTypes('available_games', 'array');
        $resolver->setAllowedTypes('selected_game', [Game::class, 'null']);
        $resolver->setAllowedTypes('publishers', 'array');
        $resolver->setAllowedTypes('genres', 'array');
        $resolver->setAllowedTypes('selected_genres', 'array');
    }
}
