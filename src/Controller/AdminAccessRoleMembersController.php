<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccessRole;
use App\Entity\User;
use App\Repository\AccessRoleRepository;
use App\Security\PermissionDelegationPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/access-roles')]
#[IsGranted('CMS_USERS_MANAGE')]
final class AdminAccessRoleMembersController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const MAX_PAGE = 10000;

    public function __construct(
        private readonly AccessRoleRepository $roles,
        private readonly PermissionDelegationPolicy $delegation,
    ) {}

    #[Route('/{id}/members', name: 'app_admin_access_role_members', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function members(AccessRole $role, Request $request): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof User || !$this->delegation->canManageRole($actor, $role)) {
            throw $this->createAccessDeniedException('Du darfst diese Rollenmitglieder nicht verwalten.');
        }

        $parameters = $request->query->all();
        $query = $this->stringParameter($parameters, 'q', '', 100);
        $state = $this->stringParameter($parameters, 'state', 'all', 10);
        if (!in_array($state, ['all', 'active', 'locked', 'unverified'], true)) {
            throw new BadRequestHttpException('The account-state filter is invalid.');
        }

        $pageValue = $this->stringParameter($parameters, 'page', '1', 5);
        if (!preg_match('/\\A[1-9][0-9]{0,4}\\z/', $pageValue)) {
            throw new BadRequestHttpException('The page number is invalid.');
        }
        $page = (int) $pageValue;
        if ($page > self::MAX_PAGE) {
            throw new BadRequestHttpException('The page number is outside the supported range.');
        }

        $total = $this->roles->countMembersForRole($role, $query, $state);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException('Diese Mitgliederseite existiert nicht.');
        }

        $response = $this->render('admin/access_role/members.html.twig', [
            'role' => $role,
            'members' => $this->roles->searchMembersForRole($role, $query, $state, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'query' => $query,
            'state' => $state,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'canManageAdmins' => $actor->isAdmin(),
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
