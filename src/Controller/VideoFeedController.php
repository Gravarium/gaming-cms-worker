<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Video;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/feeds')]
final class VideoFeedController extends AbstractController
{
    private const ITEM_LIMIT = 50;

    public function __construct(private readonly VideoRepository $videos)
    {
    }

    #[Route('/videos.xml', name: 'app_video_feed_rss', methods: ['GET'])]
    public function rss(Request $request): Response
    {
        /** @var list<Video> $videos */
        $videos = array_slice($this->videos->findPublished(), 0, self::ITEM_LIMIT);
        $response = $this->render('video/feed.xml.twig', ['videos' => $videos]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(300);
        $response->setEtag($this->fingerprint($videos));
        $response->isNotModified($request);

        return $response;
    }

    /** @param list<Video> $videos */
    private function fingerprint(array $videos): string
    {
        $items = array_map(
            static fn (Video $video): string => hash('sha256', implode("\0", [
                (string) $video->getId(),
                $video->getSlug(),
                $video->getTitle(),
                $video->getDescription(),
                $video->getPublishedAt()?->format(DATE_ATOM) ?? '',
            ])),
            $videos,
        );

        return hash('sha256', implode('|', $items));
    }
}
