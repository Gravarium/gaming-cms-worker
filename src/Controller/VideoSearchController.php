<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VideoSearchController extends AbstractController
{
    public function __construct(private readonly VideoRepository $videos)
    {
    }

    #[Route('/videos/search', name: 'app_video_search', methods: ['GET'], priority: 100)]
    public function search(Request $request): Response
    {
        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? null;
        $submitted = array_key_exists('q', $parameters);
        $query = '';
        $valid = false;

        if (is_string($rawQuery) && mb_check_encoding($rawQuery, 'UTF-8')) {
            $candidate = trim($rawQuery);
            $length = mb_strlen($candidate, 'UTF-8');
            if ($length >= 2 && $length <= 100) {
                $query = $candidate;
                $valid = true;
            }
        }

        $response = $this->render('video/search.html.twig', [
            'query' => $query,
            'submitted' => $submitted,
            'valid' => $valid,
            'videos' => $valid ? $this->videos->searchPublished($query) : [],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
