<?php

declare(strict_types=1);

namespace App\Controller\GameCatalogue;

use App\GameReleaseArchive\PublicGameReleaseArchiveQuery;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameReleaseArchiveController extends AbstractController
{
    public function __construct(
        private readonly PublicGameReleaseArchiveQuery $archive,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/games/releases/archive', name: 'app_game_catalogue_release_archive', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $parameters = $request->query->all();
        $yearValue = $parameters['year'] ?? '';
        $pageValue = $parameters['page'] ?? '1';
        if (!is_string($yearValue) || !is_string($pageValue)
            || ($yearValue !== '' && preg_match('/\A[0-9]{4}\z/D', $yearValue) !== 1)
            || preg_match('/\A[1-9][0-9]{0,4}\z/D', $pageValue) !== 1
            || (int) $pageValue > 10000) {
            return $this->invalidFilter();
        }

        $today = new \DateTimeImmutable('today');
        $currentYear = (int) $today->format('Y');
        $years = range($currentYear, $currentYear - 4);
        $year = $yearValue === '' ? $currentYear : (int) $yearValue;
        if (!in_array($year, $years, true)) {
            return $this->invalidFilter();
        }

        $from = new \DateTimeImmutable(sprintf('%04d-01-01', $year), $today->getTimezone());
        $nextYear = $from->modify('+1 year');
        $to = $nextYear < $today ? $nextYear : $today;
        $total = $this->archive->count($from, $to);
        $pages = max(1, (int) ceil($total / PublicGameReleaseArchiveQuery::PAGE_SIZE));
        $page = (int) $pageValue;
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_catalogue/release_archive.html.twig', [
            'releases' => $this->archive->page($from, $to, $page),
            'year' => $year,
            'years' => $years,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    private function invalidFilter(): Response
    {
        return new Response('Ungültige Archivfilter.', Response::HTTP_UNPROCESSABLE_ENTITY, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
