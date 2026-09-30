<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AdminUserDirectoryRepository;
use App\Security\CmsPermission;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(CmsPermission::USERS)]
final class AdminUserDirectoryController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const MAX_QUERY_LENGTH = 180;

    public function __construct(private readonly AdminUserDirectoryRepository $users)
    {
    }

    #[Route('/admin/users/directory', name: 'app_admin_user_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? '';
        $rawState = $parameters['state'] ?? '';
        $rawPage = $parameters['page'] ?? '1';

        if (
            !is_string($rawQuery)
            || !mb_check_encoding($rawQuery, 'UTF-8')
            || mb_strlen($rawQuery) > self::MAX_QUERY_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $rawQuery) === 1
            || !is_string($rawState)
            || !in_array($rawState, ['', 'active', 'locked', 'unverified'], true)
            || !is_string($rawPage)
            || preg_match('/^[1-9][0-9]{0,8}$/D', $rawPage) !== 1
        ) {
            throw new BadRequestHttpException('Ungültige Benutzerverzeichnis-Suche.');
        }

        $query = trim($rawQuery);
        $state = $rawState === '' ? null : $rawState;
        $now = new DateTimeImmutable();
        $total = $this->users->countUsers($query, $state, $now);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $currentPage = min((int) $rawPage, $pageCount);
        $users = $this->users->findUsers(
            $query,
            $state,
            $now,
            self::PAGE_SIZE,
            ($currentPage - 1) * self::PAGE_SIZE,
        );

        $response = $this->render('admin/user/directory.html.twig', [
            'users' => $users,
            'query' => $query,
            'state' => $state,
            'total' => $total,
            'currentPage' => $currentPage,
            'pageCount' => $pageCount,
            'canManageAdmins' => $this->isGranted('ROLE_ADMIN'),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
