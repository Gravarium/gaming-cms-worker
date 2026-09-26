<?php

declare(strict_types=1);

namespace App\Controller\GameCatalogue;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameHubLink;
use App\Module\CmsModuleManager;
use App\Repository\GameCatalogue\GameCatalogueEntryRepository;
use App\Repository\GameCatalogue\GameReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/games')]
final class GameCatalogueController extends AbstractController
{
    public function __construct(
        private readonly GameCatalogueEntryRepository $entries,
        private readonly GameReleaseRepository $releases,
        private readonly EntityManagerInterface $entityManager,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_game_catalogue_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAvailable();

        $parameters = $request->query->all();
        $genres = $this->entries->publicGenres();
        $platforms = $this->entries->publicPlatforms();

        /** @var array<string, true> $genreSlugs */
        $genreSlugs = [];
        foreach ($genres as $genre) {
            $genreSlugs[$genre['slug']] = true;
        }

        /** @var array<string, true> $platformSlugs */
        $platformSlugs = [];
        foreach ($platforms as $platform) {
            $platformSlugs[$platform['slug']] = true;
        }

        $invalidFilter = false;
        $genreSlug = $this->selectedFilter($parameters, 'genre', $genreSlugs, 120, $invalidFilter);
        $platformSlug = $this->selectedFilter($parameters, 'platform', $platformSlugs, 140, $invalidFilter);

        return $this->render('game_catalogue/index.html.twig', [
            'entries' => $invalidFilter ? [] : $this->entries->publicEntries($genreSlug, $platformSlug),
            'genres' => $genres,
            'platforms' => $platforms,
            'selectedGenre' => $genreSlug,
            'selectedPlatform' => $platformSlug,
            'hasFilters' => $genreSlug !== null || $platformSlug !== null,
            'invalidFilter' => $invalidFilter,
        ]);
    }

    #[Route('/releases', name: 'app_game_catalogue_releases', methods: ['GET'])]
    public function releases(): Response
    {
        $this->assertAvailable();
        $from = new \DateTimeImmutable('today');
        $to = $from->modify('+18 months');

        return $this->render('game_catalogue/releases.html.twig', [
            'releases' => $this->releases->upcoming($from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }

    #[Route('/{slug}', name: 'app_game_catalogue_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug): Response
    {
        $this->assertAvailable();
        $entry = $this->entries->publicBySlug($slug);
        if (!$entry instanceof GameCatalogueEntry) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_catalogue/show.html.twig', [
            'entry' => $entry,
            'releases' => $this->releases->forEntry($entry),
            'links' => $this->entityManager->getRepository(GameHubLink::class)->findBy(['entry' => $entry], ['targetType' => 'ASC', 'label' => 'ASC']),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, true> $availableSlugs
     */
    private function selectedFilter(
        array $query,
        string $name,
        array $availableSlugs,
        int $maxCharacters,
        bool &$invalidFilter,
    ): ?string {
        if (!array_key_exists($name, $query)) {
            return null;
        }

        $value = $query[$name];
        if ($value === '') {
            return null;
        }

        if (
            !is_string($value)
            || strlen($value) > $maxCharacters * 4
            || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > $maxCharacters
            || !isset($availableSlugs[$value])
        ) {
            $invalidFilter = true;

            return null;
        }

        return $value;
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}
