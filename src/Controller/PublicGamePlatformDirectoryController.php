<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\CmsModuleManager;
use App\Repository\GameCatalogue\GameCatalogueEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/games')]
final class PublicGamePlatformDirectoryController extends AbstractController
{
    public function __construct(
        private readonly GameCatalogueEntryRepository $entries,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/platforms', name: 'app_game_catalogue_platform_directory', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();

        $platforms = [];
        foreach ($this->entries->publicPlatforms() as $platform) {
            $platform['total'] = $this->entries->countPublicEntries(null, $platform['slug']);
            $platforms[] = $platform;
        }

        return $this->render('game_catalogue/platform_directory.html.twig', [
            'platforms' => $platforms,
        ]);
    }

    #[Route('/platforms/{slug}', name: 'app_game_catalogue_platform', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $this->assertAvailable();

        $platform = null;
        foreach ($this->entries->publicPlatforms() as $candidate) {
            if ($candidate['slug'] === $slug) {
                $platform = $candidate;
                break;
            }
        }
        if ($platform === null) {
            throw $this->createNotFoundException();
        }

        $parameters = $request->query->all();
        $rawPage = $parameters['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/\A[1-9][0-9]{0,3}\z/', $rawPage) !== 1) {
            throw $this->createNotFoundException();
        }
        $page = (int) $rawPage;
        if ($page > GameCatalogueEntryRepository::MAX_PAGE) {
            throw $this->createNotFoundException();
        }

        $total = $this->entries->countPublicEntries(null, $slug);
        $totalPages = min(
            GameCatalogueEntryRepository::MAX_PAGE,
            max(1, (int) ceil($total / GameCatalogueEntryRepository::PAGE_SIZE)),
        );
        if ($page > $totalPages) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_catalogue/platform.html.twig', [
            'platform' => $platform,
            'entries' => $this->entries->publicEntries(null, $slug, $page),
            'page' => $page,
            'total' => $total,
            'totalPages' => $totalPages,
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}
