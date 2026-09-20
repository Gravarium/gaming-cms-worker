<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use App\Form\ContentEntryType;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentRevisionRepository;
use App\Service\ContentRevisionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/content')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentController extends AbstractController
{
    public function __construct(
        private readonly ContentEntryRepository $entries,
        private readonly ContentRevisionRepository $revisions,
        private readonly ContentRevisionManager $revisionManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'app_admin_content_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = trim((string) $request->query->get('q'));
        $status = $request->query->getString('status');
        $type = $request->query->getString('type');

        return $this->render('admin/content/index.html.twig', [
            'entries' => $this->entries->searchAdmin($query, $status, $type),
            'filters' => ['q' => $query, 'status' => $status, 'type' => $type],
        ]);
    }

    #[Route('/new', name: 'app_admin_content_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $entry = new ContentEntry();
        $form = $this->createForm(ContentEntryType::class, $entry)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->requireUser();
            $entry->setAuthor($user)->setSlug($this->createUniqueSlug($entry->getTitle()));
            $entry->synchronizePublication();

            $this->entityManager->persist($entry);
            $this->entityManager->flush();
            $this->revisionManager->capture($entry, $user);
            $this->entityManager->flush();

            $this->addFlash('success', 'Der Inhalt und seine erste Revision wurden gespeichert.');

            return $this->redirectToRoute('app_admin_content_index');
        }

        return $this->render('admin/content/form.html.twig', [
            'form' => $form,
            'heading' => 'Neuen Inhalt erstellen',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_content_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(ContentEntry $entry, Request $request): Response
    {
        $form = $this->createForm(ContentEntryType::class, $entry)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entry->setSlug($this->createUniqueSlug($entry->getTitle(), $entry->getId()));
            $entry->synchronizePublication();
            $this->revisionManager->capture($entry, $this->requireUser());
            $this->entityManager->flush();

            $this->addFlash('success', 'Die Änderungen wurden als neue Revision gespeichert.');

            return $this->redirectToRoute('app_admin_content_index');
        }

        return $this->render('admin/content/form.html.twig', [
            'form' => $form,
            'heading' => 'Inhalt bearbeiten',
            'entry' => $entry,
        ]);
    }

    #[Route('/{id}/history', name: 'app_admin_content_history', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function history(ContentEntry $entry): Response
    {
        return $this->render('admin/content/history.html.twig', [
            'entry' => $entry,
            'revisions' => $this->revisions->forEntry($entry),
        ]);
    }

    #[Route('/{id}/revisions/{revisionId}/restore', name: 'app_admin_content_restore', requirements: ['id' => '\\d+', 'revisionId' => '\\d+'], methods: ['POST'])]
    public function restore(ContentEntry $entry, int $revisionId, Request $request): Response
    {
        $revision = $this->revisions->find($revisionId);
        if ($revision === null || $revision->getEntry() !== $entry) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('restore-content-'.$entry->getId().'-'.$revisionId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }

        $this->revisionManager->restore($entry, $revision, $this->requireUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'Revision '.$revision->getRevisionNumber().' wurde als Entwurf wiederhergestellt.');

        return $this->redirectToRoute('app_admin_content_edit', ['id' => $entry->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_admin_content_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(ContentEntry $entry, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-content-'.$entry->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();
        $this->addFlash('success', 'Der Inhalt wurde gelöscht.');

        return $this->redirectToRoute('app_admin_content_index');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function createUniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = mb_strtolower($this->slugger->slug($title)->toString());
        $base = $base !== '' ? $base : 'inhalt';
        $slug = $base;
        $suffix = 2;

        while ($this->entries->slugExists($slug, $exceptId)) {
            $slug = $base.'-'.$suffix;
            ++$suffix;
        }

        return $slug;
    }
}
