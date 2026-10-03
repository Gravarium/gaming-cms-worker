<?php

declare(strict_types=1);

namespace App\Controller\GameCatalogue;

use App\GamePlatformDirectory\PublicGamePlatformQuery;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/games/platforms')]
final class GamePlatformDirectoryController extends AbstractController
{
    public function __construct(
        private readonly PublicGamePlatformQuery $platforms,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_game_platform_directory', priority: 100, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        $result = $this->platforms->platforms($page);
        if ($page > $result['totalPages']) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_platform_directory/index.html.twig', [...$result, 'page' => $page]);
    }

    #[Route('/{slug}', name: 'app_game_platform_show', requirements: ['slug' => '[a-z0-9-]{1,140}'], methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        [$region, $status, $year] = $this->filters($request);
        $result = $this->platforms->platform($slug, $page, $region, $status, $year);
        if ($result === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_platform_directory/show.html.twig', [
            ...$result,
            'page' => $page,
            'selectedRegion' => $region,
            'selectedStatus' => $status,
            'selectedYear' => $year,
        ]);
    }

    /** @return array{?string, ?string, ?int} */
    private function filters(Request $request): array
    {
        $parameters = $request->query->all();
        $region = $parameters['region'] ?? null;
        if ($region !== null && (!is_string($region) || preg_match('/\A[\pL\pN ._-]{1,60}\z/u', $region) !== 1)) {
            throw $this->createNotFoundException();
        }
        $status = $parameters['status'] ?? null;
        if ($status !== null && (!is_string($status) || !in_array($status, ['announced', 'released', 'delayed'], true))) {
            throw $this->createNotFoundException();
        }
        $rawYear = $parameters['year'] ?? null;
        if ($rawYear !== null && (!is_string($rawYear) || preg_match('/\A(?:19[7-9][0-9]|2[0-1][0-9]{2}|2200)\z/', $rawYear) !== 1)) {
            throw $this->createNotFoundException();
        }

        return [$region, $status, $rawYear === null ? null : (int) $rawYear];
    }

    private function page(Request $request): int
    {
        $value = $request->query->all()['page'] ?? '1';
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1) {
            throw $this->createNotFoundException();
        }

        $page = (int) $value;
        if ($page > PublicGamePlatformQuery::MAX_PAGE) {
            throw $this->createNotFoundException();
        }

        return $page;
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}
