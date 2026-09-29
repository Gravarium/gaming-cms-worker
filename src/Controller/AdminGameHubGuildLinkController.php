<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameHubLink;
use App\Entity\Guild;
use App\Form\GameCatalogue\GameHubGuildLinkType;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(CmsPermission::GAMING)]
final class AdminGameHubGuildLinkController extends AbstractController
{
    #[Route('/admin/gaming/game-hubs/{entryId}/guild-links', name: 'app_admin_gaming_game_hub_guild_links', methods: ['GET', 'POST'])]
    public function index(int $entryId, Request $request, EntityManagerInterface $entityManager): Response
    {
        $entry = $entityManager->find(GameCatalogueEntry::class, $entryId);
        if (!$entry instanceof GameCatalogueEntry) {
            throw $this->createNotFoundException();
        }

        $game = $entry->getGame();
        $form = $this->createForm(GameHubGuildLinkType::class, null, [
            'game' => $game,
            'csrf_token_id' => 'game-hub-guild-link-'.$entryId,
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $guild = $form->get('guild')->getData();
            $guildId = $guild instanceof Guild ? $guild->getId() : null;
            $guildGame = $guild instanceof Guild ? $guild->getGame() : null;

            if (
                !$guild instanceof Guild
                || $guildId === null
                || !$guild->isEnabled()
                || !$game->isEnabled()
                || $guildGame?->getId() !== $game->getId()
            ) {
                $form->addError(new FormError('Diese Gilde ist für diesen Game Hub nicht verfügbar.'));
            } elseif ($entityManager->getRepository(GameHubLink::class)->findOneBy([
                'entry' => $entry,
                'targetType' => 'guild',
                'targetId' => $guildId,
            ]) !== null) {
                $form->addError(new FormError('Diese Gilde ist bereits mit dem Game Hub verknüpft.'));
            } else {
                $entityManager->persist(new GameHubLink($entry, 'guild', $guildId, $guild->getName()));
                $entityManager->flush();
                $this->addFlash('success', 'Die Gilde wurde mit dem Game Hub verknüpft.');

                return $this->redirectToRoute('app_admin_gaming_game_hub_guild_links', ['entryId' => $entryId]);
            }
        }

        $links = $entityManager->getRepository(GameHubLink::class)->findBy(
            ['entry' => $entry, 'targetType' => 'guild'],
            ['label' => 'ASC', 'targetId' => 'ASC'],
        );
        $response = $this->render('admin/game_catalogue/guild_links.html.twig', [
            'entry' => $entry,
            'links' => $links,
            'form' => $form->createView(),
        ]);
        if ($form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/admin/gaming/game-hubs/{entryId}/guild-links/{guildId}/delete', name: 'app_admin_gaming_game_hub_guild_link_delete', methods: ['POST'])]
    public function delete(int $entryId, int $guildId, Request $request, EntityManagerInterface $entityManager): Response
    {
        $entry = $entityManager->find(GameCatalogueEntry::class, $entryId);
        if (!$entry instanceof GameCatalogueEntry) {
            throw $this->createNotFoundException();
        }

        $link = $entityManager->getRepository(GameHubLink::class)->findOneBy([
            'entry' => $entry,
            'targetType' => 'guild',
            'targetId' => $guildId,
        ]);
        if (!$link instanceof GameHubLink) {
            throw $this->createNotFoundException();
        }

        $submittedToken = $request->request->all()['_token'] ?? null;
        $tokenId = 'delete-game-hub-guild-link-'.$entryId.'-'.$guildId;
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid($tokenId, $submittedToken)) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($link);
        $entityManager->flush();
        $this->addFlash('success', 'Die Gildenverknüpfung wurde entfernt.');

        return $this->redirectToRoute('app_admin_gaming_game_hub_guild_links', ['entryId' => $entryId]);
    }
}
