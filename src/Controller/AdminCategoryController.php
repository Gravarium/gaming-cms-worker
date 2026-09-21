<?php

declare(strict_types=1);
namespace App\Controller;
use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Repository\ContentEntryRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
#[Route('/admin/categories')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminCategoryController extends AbstractController
{
    public function __construct(private readonly CategoryRepository $categories, private readonly ContentEntryRepository $entries, private readonly EntityManagerInterface $entityManager, private readonly SluggerInterface $slugger, private readonly AuditLogger $audit) {}
    #[Route('', name: 'app_admin_category_index', methods: ['GET'])]
    public function index(): Response { return $this->render('admin/category/index.html.twig', ['categories' => $this->categories->findBy([], ['name' => 'ASC'])]); }
    #[Route('/new', name: 'app_admin_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response { return $this->form(new Category(), $request, 'Kategorie anlegen'); }
    #[Route('/{id}/edit', name: 'app_admin_category_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Category $category, Request $request): Response { return $this->form($category, $request, 'Kategorie bearbeiten'); }
    #[Route('/{id}/delete', name: 'app_admin_category_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Category $category, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-category-'.$category->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        if (!$category->getChildren()->isEmpty() || $this->entries->count(['category' => $category]) > 0) { $this->addFlash('error', 'Die Kategorie wird noch verwendet oder enthält Unterkategorien. Verschiebe diese zuerst.'); return $this->redirectToRoute('app_admin_category_index'); }
        $id = $category->getId(); $this->audit->record('content_category.delete', Category::class, $id, 'Content-Kategorie gelöscht.');
        $this->entityManager->remove($category); $this->entityManager->flush(); $this->addFlash('success', 'Die Kategorie wurde gelöscht.');
        return $this->redirectToRoute('app_admin_category_index');
    }
    private function form(Category $category, Request $request, string $heading): Response
    {
        $form = $this->createForm(CategoryType::class, $category)->handleRequest($request);
        if ($form->isSubmitted()) {
            $parent = $form->get('parent')->getData();
            try {
                $category->setParent($parent instanceof Category ? $parent : null);
            } catch (\DomainException $exception) {
                $form->get('parent')->addError(new FormError($exception->getMessage()));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $category->setSlug($this->uniqueSlug($category->getName(), $category->getId()));
            if ($category->getId() === null) { $this->entityManager->persist($category); }
            $this->audit->record('content_category.save', $category, $category->getId(), 'Content-Kategorie gespeichert.');
            $this->entityManager->flush(); $this->addFlash('success', 'Die Kategorie wurde gespeichert.');
            return $this->redirectToRoute('app_admin_category_index');
        }
        $response = $this->render('admin/category/form.html.twig', ['form' => $form, 'heading' => $heading]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }
    private function uniqueSlug(string $name, ?int $exceptId): string { $base = mb_strtolower($this->slugger->slug($name)->toString()) ?: 'kategorie'; $slug = $base; $number = 2; while ($this->categories->slugExists($slug, $exceptId)) { $slug = $base.'-'.$number++; } return $slug; }
}
