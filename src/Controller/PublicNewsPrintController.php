<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicNewsPrintController extends AbstractController
{
    public function __construct(private readonly ContentEntryRepository $entries)
    {
    }

    #[Route('/news/print/{slug}', name: 'app_news_print', methods: ['GET'])]
    public function show(string $slug): Response
    {
        $entry = $this->entries->findPublishedBySlug($slug, ContentEntry::TYPE_NEWS);
        if (!$entry instanceof ContentEntry || $entry->isUnlisted()) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('content_print/news_print.html.twig', [
            'entry' => $entry,
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
