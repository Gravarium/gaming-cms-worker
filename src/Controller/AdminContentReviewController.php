<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentReview\ReviewDecision;
use App\ContentReview\ReviewQueue;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Form\ContentReviewDecisionType;
use App\Service\AuditLogger;
use App\Service\ContentRevisionManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/review', name: 'app_admin_content_review_')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentReviewController extends AbstractController
{
    public function __construct(
        private readonly ReviewQueue $queue,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentRevisionManager $revisions,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = $request->query->all();
        try {
            $queue = $this->queue->read($query['page'] ?? null);
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültige Seitenzahl.', Response::HTTP_BAD_REQUEST));
        } catch (\OutOfRangeException $exception) {
            throw $this->createNotFoundException('Diese Seite der Freigabe-Warteschlange existiert nicht.', $exception);
        }

        return $this->privateResponse($this->render('@content_review/review_queue.html.twig', ['queue' => $queue]));
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ContentEntry $entry): Response
    {
        if ($entry->getStatus() !== ContentEntry::STATUS_REVIEW) {
            throw $this->createNotFoundException();
        }

        return $this->renderDetail($entry, $this->decisionForm($entry));
    }

    #[Route('/{id}/decision', name: 'decision', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decide(ContentEntry $entry, Request $request): Response
    {
        $form = $this->decisionForm($entry);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($entry, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $decision = $form->getData();
        if ($decision->decision === null) {
            $form->addError(new FormError('Bitte wähle eine gültige Entscheidung.'));
            return $this->renderDetail($entry, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($decision->expectedUpdatedAt === null || $decision->expectedUpdatedAt === '') {
            $form->get('expectedUpdatedAt')->addError(new FormError('Bitte lade die aktuelle Review-Seite erneut.'));
            return $this->renderDetail($entry, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $expectedUpdatedAt = $decision->expectedUpdatedAt;
        if ($decision->decision === ReviewDecision::ACTION_SCHEDULE && $decision->scheduledAt === null) {
            $form->get('scheduledAt')->addError(new FormError('Für eine geplante Veröffentlichung ist ein Zeitpunkt erforderlich.'));
            return $this->renderDetail($entry, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($decision->decision !== ReviewDecision::ACTION_SCHEDULE && $decision->scheduledAt !== null) {
            $form->get('scheduledAt')->addError(new FormError('Ein Veröffentlichungszeitpunkt ist nur für die geplante Veröffentlichung erlaubt.'));
            return $this->renderDetail($entry, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $outcome = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($entry, $decision, $actor, $expectedUpdatedAt): string {
            $entityManager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
            if ($entry->getStatus() !== ContentEntry::STATUS_REVIEW) {
                return 'stale';
            }
            if (!hash_equals($expectedUpdatedAt, $entry->getUpdatedAt()->format(DATE_ATOM))) {
                return 'stale_content';
            }

            $now = new \DateTimeImmutable();
            if ($decision->decision === ReviewDecision::ACTION_PUBLISH) {
                $unpublishAt = $entry->getScheduledUnpublishAt();
                if ($unpublishAt !== null && $unpublishAt <= $now) {
                    return 'invalid_window';
                }
                $entry->setStatus(ContentEntry::STATUS_PUBLISHED);
            } elseif ($decision->decision === ReviewDecision::ACTION_SCHEDULE) {
                $scheduledAt = $decision->scheduledAt;
                if ($scheduledAt === null || $scheduledAt <= $now) {
                    return 'invalid_window';
                }
                $unpublishAt = $entry->getScheduledUnpublishAt();
                if ($unpublishAt !== null && $unpublishAt <= $scheduledAt) {
                    return 'invalid_window';
                }
                $entry->setStatus(ContentEntry::STATUS_SCHEDULED)->setScheduledAt($scheduledAt);
            } elseif ($decision->decision === ReviewDecision::ACTION_REQUEST_CHANGES) {
                $entry->setStatus(ContentEntry::STATUS_DRAFT);
            } else {
                return 'stale';
            }

            $entry->synchronizePublication();
            $this->revisions->capture($entry, $actor);
            $this->audit->record(
                'content.review.decision',
                $entry,
                $entry->getId(),
                'Redaktionelle Freigabeentscheidung gespeichert.',
                ['decision' => $decision->decision, 'reason' => $decision->reason],
            );

            return 'updated';
        });

        if ($outcome === 'stale') {
            $this->addFlash('error', 'Dieser Inhalt ist nicht mehr zur Freigabe vorgemerkt.');
            return $this->privateResponse($this->redirectToRoute('app_admin_content_review_index'));
        }
        if ($outcome === 'stale_content') {
            $this->addFlash('error', 'Der Inhalt wurde seit dem Öffnen dieser Seite geändert. Bitte prüfe die aktuelle Version erneut.');
            return $this->privateResponse($this->redirectToRoute('app_admin_content_review_show', ['id' => $entry->getId()]));
        }
        if ($outcome === 'invalid_window') {
            $form->get('scheduledAt')->addError(new FormError('Der Veröffentlichungszeitpunkt muss in der Zukunft und vor dem geplanten Ende liegen.'));
            return $this->renderDetail($entry, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = match ($decision->decision) {
            ReviewDecision::ACTION_PUBLISH => 'Der Inhalt wurde veröffentlicht.',
            ReviewDecision::ACTION_SCHEDULE => 'Die Veröffentlichung wurde geplant.',
            ReviewDecision::ACTION_REQUEST_CHANGES => 'Der Inhalt wurde zur Überarbeitung zurückgegeben.',
            default => 'Die Entscheidung wurde gespeichert.',
        };
        $this->addFlash('success', $message);

        return $this->privateResponse($this->redirectToRoute('app_admin_content_review_index'));
    }

    /** @return FormInterface<ReviewDecision> */
    private function decisionForm(ContentEntry $entry): FormInterface
    {
        $id = $entry->getId();
        if ($id === null) {
            throw $this->createNotFoundException();
        }

        $decision = new ReviewDecision();
        $decision->expectedUpdatedAt = $entry->getUpdatedAt()->format(DATE_ATOM);

        return $this->createForm(ContentReviewDecisionType::class, $decision, [
            'action' => $this->generateUrl('app_admin_content_review_decision', ['id' => $id]),
            'method' => 'POST',
        ]);
    }

    /** @param FormInterface<ReviewDecision> $form */
    private function renderDetail(ContentEntry $entry, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('@content_review/review_detail.html.twig', [
            'entry' => $entry,
            'form' => $form->createView(),
        ]);
        $response->setStatusCode($status);

        return $this->privateResponse($response);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
