<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\GuildEventRepository;
use App\Repository\GuildRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGuildEventController extends AbstractController
{
    public function __construct(
        private readonly GuildRepository $guilds,
        private readonly GuildEventRepository $events,
    ) {
    }

    #[Route('/gaming/guild/{slug}/events', name: 'app_guild_event_index', methods: ['GET'])]
    public function index(string $slug): Response
    {
        $guild = $this->guilds->findPublicBySlug($slug);
        if ($guild === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('gaming/events.html.twig', [
            'guild' => $guild,
            'events' => $this->events->upcomingForGuild($guild),
        ]);
    }
}
