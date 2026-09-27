<?php

declare(strict_types=1);

namespace App\Controller\GameCatalogue;

use App\Module\CmsModuleManager;
use App\Repository\GameCatalogue\GameReleaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameReleaseCalendarController extends AbstractController
{
    private const PAGE_SIZE = 20;

    /** @var array<string, string> */
    private const STATUS_OPTIONS = [
        'announced' => 'Angekündigt',
        'delayed' => 'Verschoben',
        'released' => 'Veröffentlicht',
    ];

    public function __construct(
        private readonly GameReleaseRepository $releases,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/games/releases/calendar', name: 'app_game_catalogue_release_calendar', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $search = $this->queryValue($request, 'q');
        $platformValue = $this->queryValue($request, 'platform');
        $region = $this->queryValue($request, 'region');
        $status = $this->queryValue($request, 'status');
        $yearValue = $this->queryValue($request, 'year');
        $pageValue = $this->queryValue($request, 'page');

        if ($search === null || $platformValue === null || $region === null || $status === null || $yearValue === null || $pageValue === null) {
            return $this->invalidFilter();
        }

        $search = trim($search);
        $region = trim($region);
        $status = trim($status);
        if (mb_strlen($search) > 100 || mb_strlen($region) > 60) {
            return $this->invalidFilter();
        }

        if ($status !== '' && !array_key_exists($status, self::STATUS_OPTIONS)) {
            return $this->invalidFilter();
        }

        $today = new \DateTimeImmutable('today');
        $currentYear = (int) $today->format('Y');
        $years = range($currentYear, $currentYear + 4);
        $year = null;

        if ($yearValue !== '') {
            if (preg_match('/\A[0-9]{4}\z/D', $yearValue) !== 1) {
                return $this->invalidFilter();
            }

            $year = (int) $yearValue;
            if (!in_array($year, $years, true)) {
                return $this->invalidFilter();
            }
        }

        $from = $today;
        $to = $today->modify('+18 months');
        if ($year !== null) {
            $yearStart = new \DateTimeImmutable(sprintf('%04d-01-01', $year), $today->getTimezone());
            $from = $year === $currentYear ? $today : $yearStart;
            $to = $yearStart->modify('+1 year');
        }

        $platformId = null;
        if ($platformValue !== '') {
            if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $platformValue) !== 1 || (int) $platformValue > 2147483647) {
                return $this->invalidFilter();
            }

            $platformId = (int) $platformValue;
        }

        $page = 1;
        if ($pageValue !== '') {
            if (preg_match('/\A[1-9][0-9]{0,4}\z/D', $pageValue) !== 1 || (int) $pageValue > 10000) {
                return $this->invalidFilter();
            }

            $page = (int) $pageValue;
        }

        $platforms = $this->releases->calendarPlatforms($today);
        $platformIds = [];
        foreach ($platforms as $platform) {
            if ($platform->getId() !== null) {
                $platformIds[] = $platform->getId();
            }
        }

        if ($platformId !== null && !in_array($platformId, $platformIds, true)) {
            return $this->invalidFilter();
        }

        $regions = $this->releases->calendarRegions($today);
        if ($region !== '' && !in_array($region, $regions, true)) {
            return $this->invalidFilter();
        }

        $total = $this->releases->countCalendarResults($from, $to, $search, $platformId, $region, $status);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        $releases = $this->releases->calendarPage(
            $from,
            $to,
            $search,
            $platformId,
            $region,
            $status,
            ($page - 1) * self::PAGE_SIZE,
            self::PAGE_SIZE,
        );

        $paginationFilters = [];
        if ($search !== '') {
            $paginationFilters['q'] = $search;
        }
        if ($platformId !== null) {
            $paginationFilters['platform'] = $platformId;
        }
        if ($region !== '') {
            $paginationFilters['region'] = $region;
        }
        if ($status !== '') {
            $paginationFilters['status'] = $status;
        }
        if ($year !== null) {
            $paginationFilters['year'] = $year;
        }

        return $this->render('game_catalogue/release_calendar.html.twig', [
            'releases' => $releases,
            'from' => $from,
            'to' => $to,
            'filters' => [
                'q' => $search,
                'platform' => $platformId,
                'region' => $region,
                'status' => $status,
                'year' => $year,
            ],
            'platforms' => $platforms,
            'regions' => $regions,
            'statuses' => self::STATUS_OPTIONS,
            'years' => $years,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'paginationFilters' => $paginationFilters,
        ]);
    }

    private function queryValue(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? '';

        return is_string($value) ? $value : null;
    }

    private function invalidFilter(): Response
    {
        return new Response('Ungültige Filter. Bitte prüfe deine Sucheingaben.', Response::HTTP_UNPROCESSABLE_ENTITY, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
