<?php

declare(strict_types=1);

namespace App\Form\GameCatalogue;

use App\Entity\Game;
use App\Entity\Guild;
use App\Repository\GuildRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class GameHubGuildLinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $game = $options['game'];

        $builder->add('guild', EntityType::class, [
            'class' => Guild::class,
            'choice_label' => static fn (Guild $guild): string => $guild->getName(),
            'placeholder' => 'Gilde auswählen',
            'invalid_message' => 'Diese Gilde ist für diesen Game Hub nicht verfügbar.',
            'query_builder' => static fn (GuildRepository $repository) => $repository->createQueryBuilder('guild')
                ->innerJoin('guild.game', 'game')
                ->andWhere('guild.game = :game')
                ->andWhere('guild.enabled = true')
                ->andWhere('game.enabled = true')
                ->setParameter('game', $game)
                ->orderBy('guild.name', 'ASC'),
            'required' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_token_id' => 'game_hub_guild_link',
        ]);
        $resolver->setRequired('game');
        $resolver->setAllowedTypes('game', Game::class);
    }
}
