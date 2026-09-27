<?php

declare(strict_types=1);

namespace App\Controller\AdminGameCatalogue;

use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\Entity\GameCatalogue\GamePublisher;
use App\Form\GameCatalogue\GameCatalogueEntryAdminType;
use App\Module\CmsModuleManager;
use App\Repository\GameCatalogue\GameCatalogueEntryRepository;
use App\Repository\GameRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/catalogue')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGameCatalogueController extends AbstractController
{
    public function __construct(
        private readonly GameCatalogueEntryRepository $entries,
        private readonly GameRepository $games,
        private readonly EntityManagerInterface $entityManager,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_admin_game_catalogue_index', methods: ['GET'])]
    public function index(): Response
    {
        $entries = $this->entries->findBy([], ['id' => 'DESC']);
        usort($entries, static fn (GameCatalogueEntry $left, GameCatalogueEntry $right): int => strnatcasecmp(
            $left->getGame()->getName(),
            $right->getGame()->getName(),
        ));

        return $this->render('admin/game_catalogue/index.html.twig', [
            'entries' => $entries,
            'gamesWithoutEntries' => $this->gamesWithoutEntry(),
            'hasGames' => $this->games->count([]) > 0,
            'moduleEnabled' => $this->modules->isEnabled('gaming'),
        ]);
    }

    #[Route('/new', name: 'app_admin_game_catalogue_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $availableGames = $this->gamesWithoutEntry();
        if ($availableGames === []) {
            $this->addFlash('error', 'Es gibt kein Spiel ohne Game Hub. Lege zuerst ein Spiel an oder bearbeite einen vorhandenen Game Hub.');

            return $this->redirectToRoute('app_admin_game_catalogue_index');
        }

        $selectedGame = $this->requestedAvailableGame($request, $availableGames);
        $entry = new GameCatalogueEntry($availableGames[0]);
        $form = $this->createForm(GameCatalogueEntryAdminType::class, $entry, $this->formOptions(
            availableGames: $availableGames,
            includeGame: true,
            selectedGame: $selectedGame,
            selectedGenres: [],
        ))->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $game = $form->get('gameChoice')->getData();
            if (!$game instanceof Game) {
                $form->get('gameChoice')->addError(new FormError('Wähle ein vorhandenes Spiel aus.'));
            } elseif ($this->entries->findOneBy(['game' => $game]) instanceof GameCatalogueEntry) {
                $form->get('gameChoice')->addError(new FormError('Für dieses Spiel gibt es bereits einen Game Hub.'));
            } else {
                $newEntry = new GameCatalogueEntry($game);
                $newEntry
                    ->setPublisher($entry->getPublisher())
                    ->setDeveloper($entry->getDeveloper())
                    ->setSummary($entry->getSummary())
                    ->setEnabled($entry->isEnabled());
                $this->syncGenres($newEntry, $form);
                $this->entityManager->persist($newEntry);

                try {
                    $this->entityManager->flush();
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash('error', 'Für dieses Spiel gibt es bereits einen Game Hub.');

                    return $this->redirectToRoute('app_admin_game_catalogue_index');
                }

                $this->addFlash('success', 'Der Game Hub wurde gespeichert.');

                return $this->redirectToRoute('app_admin_game_catalogue_index');
            }
        }

        return $this->render('admin/game_catalogue/form.html.twig', [
            'form' => $form,
            'heading' => 'Game Hub anlegen',
            'entry' => null,
            'moduleEnabled' => $this->modules->isEnabled('gaming'),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_game_catalogue_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(GameCatalogueEntry $entry, Request $request): Response
    {
        $form = $this->createForm(GameCatalogueEntryAdminType::class, $entry, $this->formOptions(
            availableGames: [],
            includeGame: false,
            selectedGame: null,
            selectedGenres: array_values($entry->getGenres()->toArray()),
        ))->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->syncGenres($entry, $form);
            $this->entityManager->flush();
            $this->addFlash('success', 'Der Game Hub wurde gespeichert.');

            return $this->redirectToRoute('app_admin_game_catalogue_index');
        }

        return $this->render('admin/game_catalogue/form.html.twig', [
            'form' => $form,
            'heading' => 'Game Hub bearbeiten',
            'entry' => $entry,
            'moduleEnabled' => $this->modules->isEnabled('gaming'),
        ]);
    }

    /** @return list<Game> */
    private function gamesWithoutEntry(): array
    {
        $coveredGames = [];
        foreach ($this->entries->findAll() as $entry) {
            $gameId = $entry->getGame()->getId();
            if ($gameId !== null) {
                $coveredGames[$gameId] = true;
            }
        }

        $available = [];
        foreach ($this->games->findBy([], ['name' => 'ASC']) as $game) {
            $gameId = $game->getId();
            if ($gameId !== null && !isset($coveredGames[$gameId])) {
                $available[] = $game;
            }
        }

        return $available;
    }

    /** @param list<Game> $availableGames */
    private function requestedAvailableGame(Request $request, array $availableGames): ?Game
    {
        $requestedId = $request->query->get('game');
        if (!is_string($requestedId) || !ctype_digit($requestedId)) {
            return null;
        }

        foreach ($availableGames as $game) {
            if ($game->getId() === (int) $requestedId) {
                return $game;
            }
        }

        return null;
    }

    /**
     * @param list<Game> $availableGames
     * @param list<GameGenre> $selectedGenres
     * @return array<string, mixed>
     */
    private function formOptions(array $availableGames, bool $includeGame, ?Game $selectedGame, array $selectedGenres): array
    {
        return [
            'available_games' => $availableGames,
            'include_game' => $includeGame,
            'selected_game' => $selectedGame,
            'publishers' => $this->entityManager->getRepository(GamePublisher::class)->findBy([], ['name' => 'ASC']),
            'genres' => $this->entityManager->getRepository(GameGenre::class)->findBy([], ['name' => 'ASC']),
            'selected_genres' => $selectedGenres,
        ];
    }

    /** @param FormInterface<GameCatalogueEntry> $form */
    private function syncGenres(GameCatalogueEntry $entry, FormInterface $form): void
    {
        /** @var list<GameGenre> $genres */
        $genres = $form->get('genres')->getData() ?? [];
        foreach ($entry->getGenres()->toArray() as $currentGenre) {
            $entry->getGenres()->removeElement($currentGenre);
        }
        foreach ($genres as $genre) {
            $entry->addGenre($genre);
        }
    }
}
