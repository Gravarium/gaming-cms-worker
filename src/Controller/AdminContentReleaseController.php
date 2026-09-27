<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentRelease\AdminContentReleaseBrowser;
use App\Entity\ContentRelease;
use App\Entity\User;
use App\Form\ContentReleaseType;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/releases')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentReleaseController extends AbstractController
{
    public function __construct(
        private readonly AdminContentReleaseBrowser $browser,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_admin_content_release_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $listing = $this->readListing($request);
        if ($listing === null) {
            return $this->invalidListingResponse();
        }

        return $this->render('admin/content_release/index.html.twig', [
            ...$listing,
            'listParameters' => $this->listParameters($listing),
        ]);
    }

    #[Route('/new', name: 'app_admin_content_release_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $listing = $this->readListing($request);
        if ($listing === null) {
            return $this->invalidListingResponse();
        }

        $parameters = $this->listParameters($listing);
        $action = $this->generateUrl('app_admin_content_release_new', $parameters);

        return $this->form(
            (new ContentRelease())->setCreatedBy($this->requireUser()),
            $request,
            'Content-Release anlegen',
            $parameters,
            $action,
        );
    }

    #[Route('/{id}/edit', name: 'app_admin_content_release_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(ContentRelease $release, Request $request): Response
    {
        $listing = $this->readListing($request);
        if ($listing === null) {
            return $this->invalidListingResponse();
        }

        $parameters = $this->listParameters($listing);
        if ($release->getStatus() === ContentRelease::STATUS_PUBLISHED) {
            $this->addFlash('error', 'Ein bereits veröffentlichter Release ist unveränderlich.');

            return $this->redirectToRoute('app_admin_content_release_index', $parameters);
        }

        $action = $this->generateUrl('app_admin_content_release_edit', ['id' => $release->getId(), ...$parameters]);

        return $this->form($release, $request, 'Content-Release bearbeiten', $parameters, $action);
    }

    #[Route('/{id}/publish', name: 'app_admin_content_release_publish', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function publish(ContentRelease $release, Request $request): Response
    {
        $listing = $this->readListing($request);
        if ($listing === null) {
            return $this->invalidListingResponse();
        }
        $parameters = $this->listParameters($listing);

        $this->assertCsrf('publish-content-release-'.$release->getId(), $request);
        if (in_array($release->getStatus(), [ContentRelease::STATUS_CANCELLED, ContentRelease::STATUS_PUBLISHED], true)) {
            throw new ConflictHttpException('Dieser Release ist bereits finalisiert und kann nicht erneut veröffentlicht werden.');
        }

        try {
            $count = $release->publish(new \DateTimeImmutable());
        } catch (\DomainException) {
            $this->addFlash('error', 'Ein Release ohne veröffentlichbare Inhalte kann nicht veröffentlicht werden.');

            return $this->redirectToRoute('app_admin_content_release_index', $parameters);
        }

        $this->audit->record('content_release.publish', $release, $release->getId(), 'Content-Release veröffentlicht.', ['count' => $count]);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Release veröffentlicht: %d Inhalte aktualisiert.', $count));

        return $this->redirectToRoute('app_admin_content_release_index', $parameters);
    }

    #[Route('/{id}/delete', name: 'app_admin_content_release_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(ContentRelease $release, Request $request): Response
    {
        $listing = $this->readListing($request);
        if ($listing === null) {
            return $this->invalidListingResponse();
        }
        $parameters = $this->listParameters($listing);

        $this->assertCsrf('delete-content-release-'.$release->getId(), $request);
        if ($release->getStatus() === ContentRelease::STATUS_PUBLISHED) {
            throw $this->createAccessDeniedException('Veröffentlichte Releases bleiben als Historie erhalten.');
        }

        $id = $release->getId();
        $this->audit->record('content_release.delete', ContentRelease::class, $id, 'Content-Release gelöscht.');
        $this->entityManager->remove($release);
        $this->entityManager->flush();
        $this->addFlash('success', 'Der Release wurde gelöscht.');

        return $this->redirectToRoute('app_admin_content_release_index', $parameters);
    }

    /** @param array<string, mixed> $listParameters */
    private function form(ContentRelease $release, Request $request, string $heading, array $listParameters, string $action): Response
    {
        $form = $this->createForm(ContentReleaseType::class, $release)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $release->synchronizeSchedule();
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->renderForm($form->createView(), $heading, $release, $listParameters, $action);
            }

            if ($release->getId() === null) {
                $this->entityManager->persist($release);
            }
            $this->audit->record('content_release.save', $release, $release->getId(), 'Content-Release gespeichert.', ['status' => $release->getStatus()]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Der Release wurde gespeichert.');

            return $this->redirectToRoute('app_admin_content_release_index', $listParameters);
        }

        return $this->renderForm($form->createView(), $heading, $release, $listParameters, $action);
    }

    /** @param array<string, mixed> $listParameters */
    private function renderForm(FormView $form, string $heading, ContentRelease $release, array $listParameters, string $action): Response
    {
        return $this->render('admin/content_release/form.html.twig', [
            'form' => $form,
            'heading' => $heading,
            'release' => $release,
            'listParameters' => $listParameters,
            'formAction' => $action,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function readListing(Request $request): ?array
    {
        $query = $request->query->all();
        try {
            return $this->browser->read($query['q'] ?? null, $query['status'] ?? null, $query['page'] ?? null);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed> $listing
     *  @return array<string, string|int>
     */
    private function listParameters(array $listing): array
    {
        $parameters = [];
        if ($listing['search'] !== '') {
            $parameters['q'] = $listing['search'];
        }
        if ($listing['status'] !== '') {
            $parameters['status'] = $listing['status'];
        }
        if ($listing['page'] > 1) {
            $parameters['page'] = $listing['page'];
        }

        return $parameters;
    }

    private function invalidListingResponse(): Response
    {
        return new Response('Ungültige Filterparameter.', Response::HTTP_BAD_REQUEST, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function assertCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
    }
}
