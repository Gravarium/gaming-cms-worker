<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ContentReleaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentReleaseController extends AbstractController
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 10000;

    public function __construct(private readonly ContentReleaseRepository $releases)
    {
    }

    #[Route('/releases', name: 'app_public_content_release_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = $this->requestedPage($request);
        $now = new \DateTimeImmutable();
        $total = $this->releases->countPublicPublished($now);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));

        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->noStore($this->render('content_release/index.html.twig', [
            'releases' => $this->releases->findPublicPublished(
                self::PAGE_SIZE,
                ($page - 1) * self::PAGE_SIZE,
                $now,
            ),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]));
    }

    #[Route('/releases/{id}', name: 'app_public_content_release_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $releaseId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($releaseId)) {
            throw $this->createNotFoundException();
        }

        $now = new \DateTimeImmutable();
        $release = $this->releases->findPublicPublishedById($releaseId, $now);
        if ($release === null) {
            throw $this->createNotFoundException();
        }

        $page = $this->requestedPage($request);
        $total = $this->releases->countPublicEntriesForRelease($releaseId, $now);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));

        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->noStore($this->render('content_release/show.html.twig', [
            'release' => $release,
            'entries' => $this->releases->findPublicEntriesForRelease(
                $releaseId,
                self::PAGE_SIZE,
                ($page - 1) * self::PAGE_SIZE,
                $now,
            ),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]));
    }

    private function requestedPage(Request $request): int
    {
        $rawPage = $request->query->all()['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/^[0-9]{1,5}$/D', $rawPage) !== 1) {
            throw new BadRequestHttpException('Ungültige Seitennummer.');
        }

        $page = (int) $rawPage;
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new BadRequestHttpException('Ungültige Seitennummer.');
        }

        return $page;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
