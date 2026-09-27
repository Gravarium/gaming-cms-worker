<?php

declare(strict_types=1);

namespace App\Controller\Search;

use App\Entity\Search\SearchDocument;
use App\Entity\User;
use App\Repository\Search\SearchDocumentRepository;
use App\Search\SearchFilters;
use App\Search\SearchIndexer;
use App\Search\SearchResult;
use App\Search\SearchResultsPage;
use App\Search\SearchService;
use App\Search\SearchViewerFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchService $search,
        private readonly SearchViewerFactory $viewers,
        private readonly SearchDocumentRepository $documents,
        private readonly SearchIndexer $indexer,
    ) {
    }

    #[Route('/search/global', name: 'app_search_global', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $rawQuery = trim($request->query->getString('q'));
        $requestedPage = $this->requestedPage($request);
        $viewer = $this->viewers->fromUser($this->currentUser());
        $hasQuery = $rawQuery !== '';
        $query = null;
        if ($hasQuery) {
            $query = $this->searchQuery($rawQuery);
            $resultPage = $this->search->searchPage($query, $filters, $viewer, $requestedPage);
        } else {
            $resultPage = $this->search->discoverPage($filters, $viewer, $requestedPage);
        }

        if ($resultPage->page !== $requestedPage) {
            return $this->redirectToRoute(
                'app_search_global',
                $this->pageParameters($rawQuery, $filters, $resultPage->page),
            );
        }

        return $this->render('search/paginated_index.html.twig', [
            'query' => $rawQuery,
            'parsedQuery' => $query,
            'filters' => $filters,
            'resultPage' => $resultPage,
            'hasQuery' => $hasQuery,
        ]);
    }

    #[Route('/search/global.json', name: 'app_search_global_json', methods: ['GET'])]
    #[Route('/feeds/discovery.json', name: 'app_search_discovery_feed_json', methods: ['GET'])]
    public function jsonFeed(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $rawQuery = trim($request->query->getString('q'));
        $viewer = $this->viewers->fromUser($this->currentUser());
        $results = $rawQuery === ''
            ? $this->search->discover($filters, $viewer, 50)
            : $this->search->search($this->searchQuery($rawQuery), $filters, $viewer, 50);

        $items = array_map(fn (SearchResult $result): array => [
            'id' => $result->document->getSourceType().':'.$result->document->getSourceId(),
            'title' => $result->document->getTitle(),
            'summary' => $this->summary($result->document),
            'type' => $result->document->getDocumentType(),
            'module' => $result->document->getModuleKey(),
            'url' => $this->documentUrl($request, $result),
            'score' => $result->score,
            'reasons' => $result->reasons,
            'date_published' => $result->document->getSourceUpdatedAt()->format(DATE_ATOM),
        ], $results);

        $response = new JsonResponse([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Discovery',
            'home_page_url' => $request->getSchemeAndHttpHost().$this->generateUrl('app_search_global'),
            'items' => $items,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/feeds/discovery.xml', name: 'app_search_discovery_feed_xml', methods: ['GET'])]
    public function xmlFeed(Request $request): Response
    {
        $filters = $this->filters($request);
        $rawQuery = trim($request->query->getString('q'));
        $viewer = $this->viewers->fromUser($this->currentUser());
        $results = $rawQuery === ''
            ? $this->search->discover($filters, $viewer, 50)
            : $this->search->search($this->searchQuery($rawQuery), $filters, $viewer, 50);

        $baseUrl = $request->getSchemeAndHttpHost();
        $xml = [
            '<?xml version="1.0" encoding="UTF-8" ?>',
            '<rss version="2.0"><channel>',
            '<title>Discovery</title>',
            '<link>'.$this->escapeXml($baseUrl.$this->generateUrl('app_search_global')).'</link>',
            '<description>Sichtbare Discovery-Ergebnisse</description>',
        ];

        foreach ($results as $result) {
            $document = $result->document;
            $route = $document->getRoute();
            $link = $route !== null
                ? $baseUrl.$route
                : $this->generateUrl('app_search_item', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            $description = mb_substr(
                strip_tags((string) ($document->getExcerpt() ?: $document->getBody())),
                0,
                320,
            );

            $xml[] = '<item>';
            $xml[] = '<title>'.$this->escapeXml($document->getTitle()).'</title>';
            $xml[] = '<link>'.$this->escapeXml($link).'</link>';
            $xml[] = '<guid isPermaLink="false">'.$this->escapeXml($document->getSourceType().':'.$document->getSourceId()).'</guid>';
            $xml[] = '<description>'.$this->escapeXml($description).'</description>';
            $xml[] = '<pubDate>'.$this->escapeXml($document->getSourceUpdatedAt()->format('r')).'</pubDate>';
            $xml[] = '</item>';
        }

        $xml[] = '</channel></rss>';
        $response = new Response(implode("\n", $xml));
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/search/item/{id}', name: 'app_search_item', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function item(int $id): Response
    {
        $document = $this->documents->find($id);
        $viewer = $this->viewers->fromUser($this->currentUser());
        if (!$document instanceof SearchDocument || !$this->search->canView($document, $viewer)) {
            throw $this->createNotFoundException();
        }

        return $this->render('search/item.html.twig', ['document' => $document]);
    }

    #[Route('/admin/search/reindex', name: 'app_admin_search_reindex', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reindex(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('search-reindex', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $report = $this->indexer->repairStaleDocuments();
        $this->addFlash('success', sprintf(
            'Suche aktualisiert: %d erstellt, %d geändert, %d entfernt.',
            $report->created,
            $report->updated,
            $report->deleted,
        ));

        return $this->redirectToRoute('app_search_global');
    }

    private function requestedPage(Request $request): int
    {
        $page = $request->query->all()['page'] ?? null;
        if ($page === null) {
            return 1;
        }

        if (is_int($page)) {
            if ($page < 1 || $page > SearchResultsPage::MAX_REQUESTED_PAGE) {
                throw new BadRequestHttpException('Die Suchseite ist ungültig.');
            }

            return $page;
        }

        if (!is_string($page) || preg_match('/^[1-9][0-9]{0,3}$/D', $page) !== 1) {
            throw new BadRequestHttpException('Die Suchseite ist ungültig.');
        }

        $pageNumber = (int) $page;
        if ($pageNumber > SearchResultsPage::MAX_REQUESTED_PAGE) {
            throw new BadRequestHttpException('Die Suchseite ist ungültig.');
        }

        return $pageNumber;
    }

    /** @return array<string, int|string> */
    private function pageParameters(string $query, SearchFilters $filters, int $page): array
    {
        $parameters = [];
        if ($query !== '') {
            $parameters['q'] = $query;
        }
        if ($filters->moduleKey !== null) {
            $parameters['module'] = $filters->moduleKey;
        }
        if ($filters->documentType !== null) {
            $parameters['type'] = $filters->documentType;
        }
        if ($page > 1) {
            $parameters['page'] = $page;
        }

        return $parameters;
    }

    private function filters(Request $request): SearchFilters
    {
        try {
            return SearchFilters::fromRequest(
                $request->query->getString('module'),
                $request->query->getString('type'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }

    private function searchQuery(string $rawQuery): \App\Search\SearchQuery
    {
        try {
            return \App\Search\SearchQuery::fromString($rawQuery);
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function summary(SearchDocument $document): string
    {
        $summary = trim((string) $document->getExcerpt());
        if ($summary !== '') {
            return mb_substr(strip_tags($summary), 0, 320);
        }

        return mb_substr(strip_tags($document->getBody()), 0, 320);
    }

    private function escapeXml(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
        $validXml = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x{FFFE}\\x{FFFF}]/u', '', $escaped);

        return $validXml ?? '';
    }

    private function documentUrl(Request $request, SearchResult $result): string
    {
        $route = $result->document->getRoute();
        if ($route !== null) {
            return $request->getSchemeAndHttpHost().$route;
        }

        $id = $result->document->getId();
        if ($id === null) {
            return $request->getSchemeAndHttpHost().$this->generateUrl('app_search_global');
        }

        return $request->getSchemeAndHttpHost().$this->generateUrl(
            'app_search_item',
            ['id' => $id],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
