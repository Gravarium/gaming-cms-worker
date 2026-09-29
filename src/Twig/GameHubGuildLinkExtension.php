<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameHubLink;
use App\Entity\Guild;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GameHubGuildLinkExtension extends AbstractExtension
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_hub_public_guild_link', [$this, 'publicGuildLink']),
        ];
    }

    /**
     * @return array{label: string, url: string}|null
     */
    public function publicGuildLink(GameHubLink $link, GameCatalogueEntry $entry): ?array
    {
        $entryId = $entry->getId();
        if ($link->getTargetType() !== 'guild' || $entryId === null || $link->getEntry()->getId() !== $entryId) {
            return null;
        }

        $guild = $this->entityManager->find(Guild::class, $link->getTargetId());
        $guildGame = $guild instanceof Guild ? $guild->getGame() : null;
        $entryGame = $entry->getGame();
        $entryGameId = $entryGame->getId();

        if (
            !$guild instanceof Guild
            || !$guild->isEnabled()
            || $guildGame === null
            || !$guildGame->isEnabled()
            || $entryGameId === null
            || $guildGame->getId() !== $entryGameId
        ) {
            return null;
        }

        return [
            'label' => $guild->getName(),
            'url' => $this->urlGenerator->generate('app_guild_show', ['slug' => $guild->getSlug()]),
        ];
    }
}
