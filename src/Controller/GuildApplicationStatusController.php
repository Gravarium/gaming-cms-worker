<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GuildApplication;
use App\Entity\User;
use App\Repository\GuildApplicationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class GuildApplicationStatusController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly GuildApplicationRepository $applications,
    ) {
    }

    #[Route('/gaming/applications', name: 'app_guild_application_status', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isEmailVerified()) {
            throw $this->createAccessDeniedException();
        }

        $email = $user->getEmail();
        $totalCount = (int) $this->applications->createQueryBuilder('application')
            ->select('COUNT(application.id)')
            ->andWhere('application.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();

        $queryParameters = $request->query->all();
        $requestedPage = $queryParameters['page'] ?? '1';
        $requestedPage = is_string($requestedPage) && preg_match('/^[0-9]{1,6}$/D', $requestedPage) === 1
            ? max(1, (int) $requestedPage)
            : 1;

        $totalPages = max(1, (int) ceil($totalCount / self::PAGE_SIZE));
        $currentPage = min($requestedPage, $totalPages);

        /** @var list<GuildApplication> $applications */
        $applications = $this->applications->createQueryBuilder('application')
            ->addSelect('guild')
            ->join('application.guild', 'guild')
            ->andWhere('application.email = :email')
            ->setParameter('email', $email)
            ->orderBy('application.createdAt', 'DESC')
            ->addOrderBy('application.id', 'DESC')
            ->setFirstResult(($currentPage - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        $response = $this->render('gaming/applications.html.twig', [
            'applications' => $applications,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
