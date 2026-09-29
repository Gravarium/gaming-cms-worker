<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentSchedule\ContentScheduleCalendar;
use App\ContentSchedule\ContentScheduleChange;
use App\ContentSchedule\ContentScheduleEvent;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Form\ContentScheduleChangeType;
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

#[Route('/admin/content/schedule', name: 'app_admin_content_schedule_')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentScheduleController extends AbstractController
{
    public function __construct(
        private readonly ContentScheduleCalendar $calendar,
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
            $calendar = $this->calendar->read($query['month'] ?? null, $query['page'] ?? null);
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültiger Monat oder Seitenzahl.', Response::HTTP_BAD_REQUEST));
        } catch (\OutOfRangeException) {
            return $this->privateResponse(new Response('Diese Kalenderseite existiert nicht.', Response::HTTP_NOT_FOUND));
        }

        return $this->privateResponse($this->render('@content_schedule/calendar.html.twig', [
            'calendar' => $calendar,
        ]));
    }

    #[Route('/{id}/{kind}/change', name: 'edit', requirements: ['id' => '[1-9][0-9]*', 'kind' => 'publication|unpublication'], methods: ['GET'])]
    public function edit(ContentEntry $entry, string $kind, Request $request): Response
    {
        $month = $this->selectedMonth($request);
        if ($month instanceof Response) {
            return $month;
        }

        $currentAt = $this->eventAt($entry, $kind);
        if ($currentAt === null || $entry->getId() === null) {
            return $this->privateResponse(new Response('Termin nicht gefunden.', Response::HTTP_NOT_FOUND));
        }

        return $this->renderChange($entry, $kind, $month, $this->changeForm($entry, $kind, $month, $currentAt));
    }

    #[Route('/{id}/{kind}/change', name: 'reschedule', requirements: ['id' => '[1-9][0-9]*', 'kind' => 'publication|unpublication'], methods: ['POST'])]
    public function reschedule(ContentEntry $entry, string $kind, Request $request): Response
    {
        $month = $this->selectedMonth($request);
        if ($month instanceof Response) {
            return $month;
        }

        $currentAt = $this->eventAt($entry, $kind);
        if ($currentAt === null || $entry->getId() === null) {
            $this->addFlash('error', 'Dieser Termin wurde inzwischen geändert.');
            return $this->privateResponse($this->redirectToRoute('app_admin_content_schedule_index', ['month' => $month]));
        }

        $form = $this->changeForm($entry, $kind, $month, $currentAt);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderChange($entry, $kind, $month, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $change = $form->getData();
        if ($change->scheduledAt === null) {
            $form->get('scheduledAt')->addError(new FormError('Bitte wähle einen gültigen Zeitpunkt.'));
            return $this->renderChange($entry, $kind, $month, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $entryId = $entry->getId();

        $outcome = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($entry, $entryId, $kind, $change, $actor): string {
            $entityManager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
            $currentAt = $this->eventAt($entry, $kind);
            if ($currentAt === null || $currentAt->format('U.u') !== $change->expectedAt) {
                return 'stale';
            }

            $scheduledAt = $change->scheduledAt;
            $now = new \DateTimeImmutable();
            if ($scheduledAt <= $now) {
                return 'invalid_window';
            }

            if ($kind === ContentScheduleEvent::KIND_PUBLICATION) {
                $unpublishAt = $entry->getScheduledUnpublishAt();
                if ($entry->getStatus() !== ContentEntry::STATUS_SCHEDULED || ($unpublishAt !== null && $unpublishAt <= $scheduledAt)) {
                    return 'invalid_window';
                }
            } elseif ($kind === ContentScheduleEvent::KIND_UNPUBLICATION) {
                $publishedAt = $entry->getPublishedAt();
                if ($entry->getStatus() !== ContentEntry::STATUS_PUBLISHED || ($publishedAt !== null && $scheduledAt <= $publishedAt)) {
                    return 'invalid_window';
                }
            } else {
                return 'stale';
            }

            $previousAt = $currentAt;
            $this->revisions->capture($entry, $actor);
            if ($kind === ContentScheduleEvent::KIND_PUBLICATION) {
                $entry->setScheduledAt($scheduledAt);
            } else {
                $entry->setScheduledUnpublishAt($scheduledAt);
            }
            $entry->synchronizePublication();
            $this->audit->record(
                'content.schedule.changed',
                $entry,
                $entryId,
                'Ein redaktioneller Veröffentlichungstermin wurde geändert.',
                [
                    'event' => $kind,
                    'previousAt' => $previousAt->format(DATE_ATOM),
                    'scheduledAt' => $scheduledAt->format(DATE_ATOM),
                    'reason' => $change->reason,
                ],
            );

            return 'updated';
        });

        if ($outcome === 'stale') {
            $this->addFlash('error', 'Dieser Termin wurde inzwischen geändert.');
            return $this->privateResponse($this->redirectToRoute('app_admin_content_schedule_index', ['month' => $month]));
        }
        if ($outcome === 'invalid_window') {
            $form->get('scheduledAt')->addError(new FormError('Der Zeitpunkt muss in der Zukunft und innerhalb des Veröffentlichungsfensters liegen.'));
            return $this->renderChange($entry, $kind, $month, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Der Veröffentlichungstermin wurde geändert.');

        return $this->privateResponse($this->redirectToRoute('app_admin_content_schedule_index', ['month' => $month]));
    }

    /** @return FormInterface<ContentScheduleChange> */
    private function changeForm(ContentEntry $entry, string $kind, string $month, \DateTimeImmutable $currentAt): FormInterface
    {
        $entryId = $entry->getId();
        if ($entryId === null) {
            throw $this->createNotFoundException();
        }

        $change = new ContentScheduleChange();
        $change->scheduledAt = $currentAt;
        $change->expectedAt = $currentAt->format('U.u');

        return $this->createForm(ContentScheduleChangeType::class, $change, [
            'action' => $this->generateUrl('app_admin_content_schedule_reschedule', [
                'id' => $entryId,
                'kind' => $kind,
                'month' => $month,
            ]),
            'method' => 'POST',
            'csrf_token_id' => 'content-schedule-'.$entryId.'-'.$kind,
        ]);
    }

    /** @param FormInterface<ContentScheduleChange> $form */
    private function renderChange(ContentEntry $entry, string $kind, string $month, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('@content_schedule/change.html.twig', [
            'entry' => $entry,
            'kind' => $kind,
            'month' => $month,
            'form' => $form->createView(),
        ]);
        $response->setStatusCode($status);

        return $this->privateResponse($response);
    }

    private function selectedMonth(Request $request): string|Response
    {
        $query = $request->query->all();
        try {
            return $this->calendar->monthKey($query['month'] ?? null);
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültiger Monat.', Response::HTTP_BAD_REQUEST));
        }
    }

    private function eventAt(ContentEntry $entry, string $kind): ?\DateTimeImmutable
    {
        if ($kind === ContentScheduleEvent::KIND_PUBLICATION && $entry->getStatus() === ContentEntry::STATUS_SCHEDULED) {
            return $entry->getScheduledAt();
        }
        if ($kind === ContentScheduleEvent::KIND_UNPUBLICATION && $entry->getStatus() === ContentEntry::STATUS_PUBLISHED) {
            return $entry->getScheduledUnpublishAt();
        }

        return null;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
