<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function rss(): Response
    {
        $videos = array_slice($this->videos->findPublished(), 0, self::ITEM_LIMIT);
        $response = $this->render('video/feed.xml.twig', ['videos' => $videos]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        return $response;
    }
}
