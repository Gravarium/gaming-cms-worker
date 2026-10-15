<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameCatalogueExport\PublicGameCatalogueCsvExporter;
use App\Module\CmsModuleManager;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameCatalogueCsvController
{
    public function __construct(
        private readonly PublicGameCatalogueCsvExporter $exporter,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/games/catalogue.csv', name: 'app_public_game_catalogue_csv', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $csv = $this->exporter->export();
        } catch (\LengthException) {
            return new Response(
                'The public game catalogue is too large for a single CSV export.',
                413,
                ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return new Response($csv, Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                'game-catalogue.csv',
            ),
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
