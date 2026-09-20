<?php

declare(strict_types=1);
namespace App\Controller;
use App\Entity\ContentRelease;
use App\Entity\User;
use App\Form\ContentReleaseType;
use App\Repository\ContentReleaseRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[Route('/admin/content/releases')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentReleaseController extends AbstractController
{
    public function __construct(private readonly ContentReleaseRepository $releases, private readonly EntityManagerInterface $entityManager, private readonly AuditLogger $audit) {}
    #[Route('', name: 'app_admin_content_release_index', methods: ['GET'])]
    public function index(): Response { return $this->render('admin/content_release/index.html.twig', ['releases' => $this->releases->findBy([], ['createdAt' => 'DESC'])]); }
    #[Route('/new', name: 'app_admin_content_release_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response { return $this->form((new ContentRelease())->setCreatedBy($this->requireUser()), $request, 'Content-Release anlegen'); }
    #[Route('/{id}/edit', name: 'app_admin_content_release_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(ContentRelease $release, Request $request): Response
    {
        if ($release->getStatus() === ContentRelease::STATUS_PUBLISHED) { $this->addFlash('error', 'Ein bereits veröffentlichter Release ist unveränderlich.'); return $this->redirectToRoute('app_admin_content_release_index'); }
        return $this->form($release, $request, 'Content-Release bearbeiten');
    }
    #[Route('/{id}/publish', name: 'app_admin_content_release_publish', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function publish(ContentRelease $release, Request $request): Response
    {
        $this->assertCsrf('publish-content-release-'.$release->getId(), $request);
        $count = $release->publish(new \DateTimeImmutable());
        $this->audit->record('content_release.publish', $release, $release->getId(), 'Content-Release veröffentlicht.', ['count' => $count]);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Release veröffentlicht: %d Inhalte aktualisiert.', $count));
        return $this->redirectToRoute('app_admin_content_release_index');
    }
    #[Route('/{id}/delete', name: 'app_admin_content_release_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(ContentRelease $release, Request $request): Response
    {
        $this->assertCsrf('delete-content-release-'.$release->getId(), $request);
        if ($release->getStatus() === ContentRelease::STATUS_PUBLISHED) { throw $this->createAccessDeniedException('Veröffentlichte Releases bleiben als Historie erhalten.'); }
        $id = $release->getId();
        $this->audit->record('content_release.delete', ContentRelease::class, $id, 'Content-Release gelöscht.');
        $this->entityManager->remove($release); $this->entityManager->flush(); $this->addFlash('success', 'Der Release wurde gelöscht.');
        return $this->redirectToRoute('app_admin_content_release_index');
    }
    private function form(ContentRelease $release, Request $request, string $heading): Response
    {
        $form = $this->createForm(ContentReleaseType::class, $release)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try { $release->synchronizeSchedule(); } catch (\DomainException $exception) { $form->addError(new FormError($exception->getMessage())); return $this->render('admin/content_release/form.html.twig', ['form' => $form, 'heading' => $heading, 'release' => $release]); }
            if ($release->getId() === null) { $this->entityManager->persist($release); }
            $this->audit->record('content_release.save', $release, $release->getId(), 'Content-Release gespeichert.', ['status' => $release->getStatus()]);
            $this->entityManager->flush(); $this->addFlash('success', 'Der Release wurde gespeichert.');
            return $this->redirectToRoute('app_admin_content_release_index');
        }
        return $this->render('admin/content_release/form.html.twig', ['form' => $form, 'heading' => $heading, 'release' => $release]);
    }
    private function requireUser(): User { $user = $this->getUser(); if (!$user instanceof User) { throw $this->createAccessDeniedException(); } return $user; }
    private function assertCsrf(string $id, Request $request): void { if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.'); } }
}
