<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\GameGuide\AdminGameGuideWorkflow;
use App\Security\CmsPermission;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guides', name: 'app_admin_gaming_guide_')]
#[IsGranted(CmsPermission::GAMING)]
final class AdminGameGuideController extends AbstractController
{
    public function __construct(private readonly AdminGameGuideWorkflow $guides)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->indexPage();
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $values = $request->isMethod('POST') ? $request->request->all() : [
            'title' => '',
            'game_id' => '',
            'guide_type' => 'build',
            'game_version' => '',
            'season' => '',
            'valid_from' => (new \DateTimeImmutable())->format('Y-m-d'),
            'valid_until' => '',
            'build_code' => '',
            'tier_criteria' => '',
            'tier_provenance' => '',
            'tier_entries_json' => '',
        ];
        unset($values['id']);
        if (!$request->isMethod('POST')) {
            return $this->form($values);
        }
        if (!$this->isCsrfTokenValid('game-guide-new', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid guide form token.');
        }

        try {
            $id = $this->guides->createDraft($this->editorId(), $values);
        } catch (\InvalidArgumentException|\DomainException|\JsonException $exception) {
            return $this->form($values, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DbalException $exception) {
            return $this->form($values, 'The guide could not be saved. Check the selected game and guide data.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Draft saved.');
        return $this->redirectToRoute('app_admin_gaming_guide_edit', ['id' => $id]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $authorId = $this->editorId();
        $guide = $this->guides->editableDraft($id, $authorId);
        if ($guide === null) {
            throw $this->createNotFoundException();
        }

        if (!$request->isMethod('POST')) {
            return $this->form($guide);
        }
        if (!$this->isCsrfTokenValid('game-guide-edit-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid guide form token.');
        }

        $values = $request->request->all();
        try {
            if (!$this->guides->updateDraft($id, $authorId, $values)) {
                throw $this->createNotFoundException();
            }
        } catch (\InvalidArgumentException|\DomainException|\JsonException $exception) {
            return $this->form(['id' => $id] + $values, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DbalException $exception) {
            return $this->form(['id' => $id] + $values, 'The guide could not be saved. Check the selected game and guide data.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Draft saved.');
        return $this->redirectToRoute('app_admin_gaming_guide_index');
    }

    #[Route('/{id}/submit', name: 'submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('game-guide-submit-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid guide submission token.');
        }
        if (!$this->guides->submit($id, $this->editorId())) {
            throw $this->createNotFoundException();
        }

        $this->addFlash('success', 'Guide submitted for independent review.');
        return $this->redirectToRoute('app_admin_gaming_guide_index');
    }

    #[Route('/{id}/review', name: 'review', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function review(int $id, Request $request): Response
    {
        $submission = $this->guides->reviewSubmission($id);
        if ($submission === null) {
            throw $this->createNotFoundException();
        }
        $editorId = $this->editorId();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('game-guide-review-'.$id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid guide review token.');
            }
            if (($submission['author_id'] ?? null) !== null && (int) $submission['author_id'] === $editorId) {
                throw $this->createAccessDeniedException('Authors cannot review their own guide.');
            }

            $decision = $request->request->getString('decision');
            $reason = $request->request->getString('reason');
            try {
                if (!$this->guides->decide($id, $editorId, $decision, $reason)) {
                    throw $this->createNotFoundException();
                }
            } catch (\InvalidArgumentException $exception) {
                return $this->reviewPage($submission, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (\DomainException $exception) {
                return $this->reviewPage($submission, 'This guide can no longer be reviewed in its current state.', Response::HTTP_CONFLICT);
            }

            $this->addFlash('success', $decision === 'publish' ? 'Guide published.' : 'Guide returned to its author.');
            return $this->redirectToRoute('app_admin_gaming_guide_index');
        }

        return $this->reviewPage($submission);
    }

    #[Route('/{id}/withdraw', name: 'withdraw', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function withdraw(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('game-guide-withdraw-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid guide withdrawal token.');
        }

        try {
            if (!$this->guides->withdrawPublished($id, $this->editorId(), $request->request->getString('reason'))) {
                throw $this->createNotFoundException();
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->indexPage($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Guide withdrawn for correction.');

        return $this->redirectToRoute('app_admin_gaming_guide_index');
    }

    private function indexPage(?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('admin/index.html.twig', [
            'guides' => $this->guides->all(),
            'error' => $error,
        ]);
        $response->setStatusCode($status);

        return $response;
    }

    /** @param array<string, mixed> $values */
    private function form(array $values, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('admin/form.html.twig', [
            'values' => $values,
            'games' => $this->guides->enabledGames(),
            'error' => $error,
            'new' => !isset($values['id']),
        ]);
        $response->setStatusCode($status);

        return $response;
    }

    /** @param array<string, mixed> $submission */
    private function reviewPage(array $submission, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('admin/review.html.twig', ['guide' => $submission, 'error' => $error]);
        $response->setStatusCode($status);

        return $response;
    }

    private function editorId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        return $user->getId();
    }
}
