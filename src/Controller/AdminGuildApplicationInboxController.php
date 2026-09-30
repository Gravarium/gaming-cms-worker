<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\GuildApplicationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/applications')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGuildApplicationInboxController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const MAX_PAGE = 10000;

    public function __construct(private readonly GuildApplicationRepository $applications) {}

    #[Route('/inbox', name: 'app_admin_guild_application_inbox', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $parameters = $request->query->all();
        $query = $this->stringParameter($parameters, 'q', '', 100);
        $status = $this->stringParameter($parameters, 'status', 'all', 8);
        if (!in_array($status, ['all', 'open', 'accepted', 'rejected'], true)) {
            throw new BadRequestHttpException('The status filter is invalid.');
        }

        $pageValue = $this->stringParameter($parameters, 'page', '1', 5);
        if (!preg_match('/\\A[1-9][0-9]{0,4}\\z/', $pageValue)) {
            throw new BadRequestHttpException('The page number is invalid.');
        }
        $page = (int) $pageValue;
        if ($page > self::MAX_PAGE) {
            throw new BadRequestHttpException('The page number is outside the supported range.');
        }

        $total = $this->applications->countForInbox($query, $status);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException('Diese Bewerbungsseite existiert nicht.');
        }

        $response = $this->render('admin/gaming/application/inbox.html.twig', [
            'applications' => $this->applications->findForInbox($query, $status, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'query' => $query,
            'status' => $status,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** @param array<string, mixed> $parameters */
    private function stringParameter(array $parameters, string $name, string $default, int $maxLength): string
    {
        if (!array_key_exists($name, $parameters)) {
            return $default;
        }

        $value = $parameters[$name];
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new BadRequestHttpException(sprintf('The "%s" parameter is invalid.', $name));
        }

        return trim($value);
    }
}
