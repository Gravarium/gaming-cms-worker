<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentReleaseExport\PublicContentReleaseCsvExporter;
use App\Module\CmsModuleManager;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentReleaseCsvController
{
    public function __construct(
        private readonly PublicContentReleaseCsvExporter $exporter,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/releases.csv', name: 'app_public_content_release_csv', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('content')) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $csv = $this->exporter->export();
        } catch (\LengthException) {
            return new Response(
                'The public content release archive is too large for a single CSV export.',
                413,
                ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return new Response($csv, Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                'content-releases.csv',
            ),
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
