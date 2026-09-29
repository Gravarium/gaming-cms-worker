<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Repository\ContentTagRepository;
use App\Repository\PublicContentTagArchiveRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentTagArchiveController extends AbstractController
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 10000;

    public function __construct(
        private readonly ContentTagRepository $tags,
        private readonly PublicContentTagArchiveRepository $entries,
    ) {
    }

    #[Route('/content/tag/{slug}', name: 'app_content_tag_archive', methods: ['GET'])]
    public function archive(string $slug, Request $request): Response
    {
        $tag = $this->tags->findOneBySlug($slug);
        if (!$tag instanceof ContentTag) {
            throw $this->createNotFoundException();
        }

        $page = max(1, min(self::MAX_PAGE, $request->query->getInt('page', 1)));
        $now = new DateTimeImmutable();
        $total = $this->entries->countPublicEntriesForTag($tag, $now);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        $entries = $this->entries->findPublicEntriesForTag(
            $tag,
            $now,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
        );
        $response = $this->render('content_tag/archive.html.twig', [
            'tag' => $tag,
            'entries' => $entries,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
        $response->setPublic();
        $response->setMaxAge(60);
        $response->setSharedMaxAge(60);
        $response->setEtag($this->fingerprint($tag, $page, $total, $entries));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @param list<ContentEntry> $entries
     */
    private function fingerprint(ContentTag $tag, int $page, int $total, array $entries): string
    {
        $parts = array_map(
            static fn (ContentEntry $entry): string => (string) $entry->getId().':'.$entry->getUpdatedAt()->format('U.u'),
            $entries,
        );

        return hash('sha256', implode('|', [(string) $tag->getId(), (string) $page, (string) $total, ...$parts]));
    }
}
