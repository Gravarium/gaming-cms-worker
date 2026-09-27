<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\PageDirectory\PublicPageDirectoryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicPageDirectoryController extends AbstractController
{
    public function __construct(private readonly PublicPageDirectoryQuery $pages)
    {
    }

    #[Route('/pages', name: 'app_page_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > PublicPageDirectoryQuery::MAX_PAGES) {
            throw $this->createNotFoundException();
        }

        $now = new \DateTimeImmutable();
        $total = $this->pages->countPublicPages($now);
        $pageCount = min(
            PublicPageDirectoryQuery::MAX_PAGES,
            max(1, (int) ceil($total / PublicPageDirectoryQuery::PAGE_SIZE)),
        );
        if ($page > $pageCount) {
            throw $this->createNotFoundException();
        }

        $entries = $this->pages->findPage($page, $now);
        $response = $this->render('page/index.html.twig', [
            'entries' => $entries,
            'page' => $page,
            'pages' => $pageCount,
            'total' => $total,
        ]);
        $response->setPublic();
        $response->setMaxAge(60);
        $response->setSharedMaxAge(60);
        $response->setEtag($this->fingerprint($entries, $page, $total));
        $response->isNotModified($request);

        return $response;
    }

    /** @param list<ContentEntry> $entries */
    private function fingerprint(array $entries, int $page, int $total): string
    {
        return hash('sha256', $page.'|'.$total.'|'.implode('|', array_map(
            static fn (ContentEntry $entry): string => (string) $entry->getId().':'.$entry->getUpdatedAt()->format('U.u'),
            $entries,
        )));
    }
}
