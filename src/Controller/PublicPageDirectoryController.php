<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicPageDirectoryController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly ContentEntryRepository $entries)
    {
    }

    #[Route('/pages', name: 'app_page_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > 10000) {
            throw $this->createNotFoundException();
        }

        $pages = array_values(array_filter(
            $this->entries->findPublishedAll(5000),
            static fn (ContentEntry $entry): bool => $entry->getType() === ContentEntry::TYPE_PAGE,
        ));
        usort($pages, static function (ContentEntry $left, ContentEntry $right): int {
            $publishedComparison = ($right->getPublishedAt()?->getTimestamp() ?? 0) <=> ($left->getPublishedAt()?->getTimestamp() ?? 0);
            if ($publishedComparison !== 0) {
                return $publishedComparison;
            }

            return ($right->getId() ?? 0) <=> ($left->getId() ?? 0);
        });

        $total = count($pages);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pageCount) {
            throw $this->createNotFoundException();
        }

        $entries = array_slice($pages, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
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
