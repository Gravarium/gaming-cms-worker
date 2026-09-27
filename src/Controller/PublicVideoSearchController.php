<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PublicVideoSearchRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicVideoSearchController extends AbstractController
{
    private const MIN_QUERY_LENGTH = 2;
    private const MAX_QUERY_LENGTH = 100;

    public function __construct(private readonly PublicVideoSearchRepository $videos)
    {
    }

    #[Route('/video-search', name: 'app_video_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? '';
        if (!is_string($rawQuery) || preg_match('//u', $rawQuery) !== 1) {
            throw new BadRequestHttpException('The search query must be a valid UTF-8 string.');
        }

        if (preg_match('/\p{Cc}/u', $rawQuery) === 1) {
            throw new BadRequestHttpException('The search query cannot contain control characters.');
        }

        $query = trim($rawQuery);
        if ($query !== '') {
            $length = mb_strlen($query, 'UTF-8');
            if ($length < self::MIN_QUERY_LENGTH || $length > self::MAX_QUERY_LENGTH) {
                throw new BadRequestHttpException('The search query must contain between 2 and 100 characters.');
            }
        }

        $rawPage = $parameters['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/\A[1-9][0-9]{0,3}\z/', $rawPage) !== 1) {
            throw new BadRequestHttpException('The page must be a positive integer.');
        }

        $page = (int) $rawPage;
        if ($page > PublicVideoSearchRepository::MAX_PAGE || ($query === '' && $page !== 1)) {
            throw new BadRequestHttpException('The requested page is outside the supported range.');
        }

        $total = 0;
        $totalPages = 1;
        $videos = [];
        if ($query !== '') {
            $now = new \DateTimeImmutable();
            $total = $this->videos->countMatches($query, $now);
            $totalPages = max(1, intdiv($total + PublicVideoSearchRepository::PAGE_SIZE - 1, PublicVideoSearchRepository::PAGE_SIZE));
            if ($page > $totalPages) {
                throw $this->createNotFoundException();
            }
            $videos = $this->videos->findMatches($query, $now, $page);
        }

        return $this->render('video_search/index.html.twig', [
            'query' => $query,
            'videos' => $videos,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}
