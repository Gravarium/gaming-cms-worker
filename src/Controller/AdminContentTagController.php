<?php

declare(strict_types=1);
namespace App\Controller;
use App\Entity\ContentTag;
use App\Form\ContentTagType;
use App\Repository\ContentTagRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
#[Route('/admin/content/tags')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentTagController extends AbstractController
{
    public function __construct(private readonly ContentTagRepository $tags, private readonly EntityManagerInterface $entityManager, private readonly SluggerInterface $slugger, private readonly AuditLogger $audit) {}
    #[Route('', name: 'app_admin_content_tag_index', methods: ['GET'])]
    public function index(): Response { return $this->render('admin/content_tag/index.html.twig', ['tags' => $this->tags->findBy([], ['name' => 'ASC'])]); }
    #[Route('/new', name: 'app_admin_content_tag_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response { return $this->form(new ContentTag(), $request, 'Tag anlegen'); }
    #[Route('/{id}/edit', name: 'app_admin_content_tag_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(ContentTag $tag, Request $request): Response { return $this->form($tag, $request, 'Tag bearbeiten'); }
    #[Route('/{id}/delete', name: 'app_admin_content_tag_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(ContentTag $tag, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-content-tag-'.$tag->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        if (!$tag->getEntries()->isEmpty()) { $this->addFlash('error', 'Der Tag wird noch von Inhalten verwendet und kann nicht gelöscht werden.'); return $this->redirectToRoute('app_admin_content_tag_index'); }
        $id = $tag->getId();
        $this->audit->record('content_tag.delete', ContentTag::class, $id, 'Content-Tag gelöscht.');
        $this->entityManager->remove($tag);
        $this->entityManager->flush();
        $this->addFlash('success', 'Der Tag wurde gelöscht.');
        return $this->redirectToRoute('app_admin_content_tag_index');
    }
    private function form(ContentTag $tag, Request $request, string $heading): Response
    {
        $form = $this->createForm(ContentTagType::class, $tag)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $tag->setSlug($this->uniqueSlug($tag->getName(), $tag->getId()));
            if ($tag->getId() === null) { $this->entityManager->persist($tag); }
            $this->audit->record('content_tag.save', $tag, $tag->getId(), 'Content-Tag gespeichert.');
            $this->entityManager->flush();
            $this->addFlash('success', 'Der Tag wurde gespeichert.');
            return $this->redirectToRoute('app_admin_content_tag_index');
        }
        return $this->render('admin/content_tag/form.html.twig', ['form' => $form, 'heading' => $heading]);
    }
    private function uniqueSlug(string $name, ?int $exceptId): string
    {
        $base = mb_strtolower($this->slugger->slug($name)->toString()) ?: 'tag'; $slug = $base; $number = 2;
        while ($this->tags->slugExists($slug, $exceptId)) { $slug = $base.'-'.$number++; }
        return $slug;
    }
}
