<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AdminContentBrowserRepository;
use App\Repository\CategoryRepository;
use App\Repository\ContentTagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentBrowserController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly AdminContentBrowserRepository $entries,
        private readonly CategoryRepository $categories,
        private readonly ContentTagRepository $tags,
    ) {
    }

    #[Route('/all', name: 'app_admin_content_browser', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $rawPage = $request->query->get('page', '1');
        if (!is_string($rawPage) || preg_match('/\A[0-9]{1,7}\z/D', $rawPage) !== 1 || (int) $rawPage < 1) {
            throw new BadRequestHttpException('The page number must be a positive integer.');
        }

        $query = mb_substr(trim($request->query->getString('q')), 0, 160);
        $status = $request->query->getString('status');
        $type = $request->query->getString('type');
        $categorySlug = $request->query->getString('category');
        $tagSlug = $request->query->getString('tag');
        $category = $categorySlug !== '' ? $this->categories->findOneBy(['slug' => $categorySlug]) : null;
        $tag = $tagSlug !== '' ? $this->tags->findOneBySlug($tagSlug) : null;
        $result = $this->entries->searchAdminPage(
            $query,
            $status,
            $type,
            $category,
            $tag,
            (int) $rawPage,
            self::PAGE_SIZE,
        );

        $response = $this->render('admin/content/browser.html.twig', [
            'entries' => $result['entries'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pageCount' => $result['pageCount'],
            'filters' => [
                'q' => $query,
                'status' => $status,
                'type' => $type,
                'category' => $categorySlug,
                'tag' => $tagSlug,
            ],
            'categories' => $this->categories->findBy([], ['name' => 'ASC']),
            'tags' => $this->tags->findBy([], ['name' => 'ASC']),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
