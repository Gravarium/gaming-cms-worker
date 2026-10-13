<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentRedirect\ContentRedirectCsvExporter;
use App\Entity\ContentEntry;
use App\Repository\ContentRedirectRepository;
use App\Security\CmsPermission;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/redirects')]
#[IsGranted(CmsPermission::CONTENT)]
final class AdminContentRedirectCsvController
{
    private const PAGE_SIZE = 250;

    #[Route('/export.csv', name: 'app_admin_content_redirect_export', methods: ['GET'])]
    public function __invoke(Request $request, ContentRedirectRepository $redirects, ContentRedirectCsvExporter $exporter): Response
    {
        try {
            [$query, $type] = $this->filters($request);
        } catch (InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültige Filterparameter.', Response::HTTP_BAD_REQUEST));
        }

        $response = new StreamedResponse(static function () use ($redirects, $exporter, $query, $type): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }

            $offset = 0;
            $bom = true;
            do {
                $page = $redirects->findAdminPage($query, $type, self::PAGE_SIZE, $offset);
                $exporter->write($page, $stream, $bom);
                $bom = false;
                $offset += count($page);
            } while (count($page) === self::PAGE_SIZE);

            fclose($stream);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="content-redirects.csv"');

        return $this->privateResponse($response);
    }

    /** @return array{string, string} */
    private function filters(Request $request): array
    {
        $all = $request->query->all();
        $query = $all['q'] ?? '';
        $type = $all['type'] ?? '';
        if (!is_string($query) || !is_string($type)
            || !mb_check_encoding($query, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $query) === 1
            || mb_strlen(trim($query), 'UTF-8') > 120
            || !in_array($type, ['', ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE], true)
        ) {
            throw new InvalidArgumentException('Invalid redirect export filter.');
        }

        return [trim($query), $type];
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
