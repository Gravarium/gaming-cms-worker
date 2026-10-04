<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionEvidenceAdmin\EvidenceReviewBoard;
use App\Entity\Competition\Competition;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/competitions/{id}/evidence', name: 'app_admin_competition_evidence', requirements: ['id' => '\\d+'], methods: ['GET'])]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCompetitionEvidenceController extends AbstractController
{
    public function __construct(
        private readonly EvidenceReviewBoard $board,
        private readonly CmsModuleManager $modules,
    ) {
    }

    public function __invoke(Competition $competition, Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        try {
            [$type, $page] = $this->filters($request);
            $result = $this->board->page($competition, $type, $page);
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültige Filterparameter.', Response::HTTP_BAD_REQUEST));
        }

        return $this->privateResponse($this->render('admin/competition_evidence/index.html.twig', [
            'competition' => $competition,
            'evidence' => $result['items'],
            'type' => $type,
            'types' => EvidenceReviewBoard::TYPES,
            'page' => $page,
            'pages' => $result['pages'],
            'total' => $result['total'],
            'available' => $result['available'],
        ]));
    }

    /** @return array{0: string|null, 1: int} */
    private function filters(Request $request): array
    {
        /** @var array<string, mixed> $parameters */
        $parameters = $request->query->all();
        if (array_diff(array_keys($parameters), ['type', 'page']) !== []) {
            throw new \InvalidArgumentException('Unknown filter.');
        }

        $rawType = $parameters['type'] ?? '';
        $rawPage = $parameters['page'] ?? '1';
        if (!is_string($rawType) || !is_string($rawPage)
            || !in_array($rawType, ['', ...EvidenceReviewBoard::TYPES], true)
            || preg_match('/\A[1-4]\z/D', $rawPage) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid filter.');
        }

        return [$rawType === '' ? null : $rawType, (int) $rawPage];
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
