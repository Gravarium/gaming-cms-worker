<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MenuItem;
use App\Form\MenuItemType;
use App\Repository\MenuItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/menu')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminMenuController extends AbstractController
{
    public function __construct(
        private readonly MenuItemRepository $items,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_menu_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/menu/index.html.twig', [
            'items' => $this->items->findBy([], ['position' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_menu_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form(new MenuItem(), $request, 'Menüpunkt anlegen');
    }

    #[Route('/{id}/edit', name: 'app_admin_menu_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(MenuItem $item, Request $request): Response
    {
        return $this->form($item, $request, 'Menüpunkt bearbeiten');
    }

    #[Route('/{id}/delete', name: 'app_admin_menu_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(MenuItem $item, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-menu-'.$item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();
        $this->addFlash('success', 'Der Menüpunkt wurde gelöscht.');

        return $this->redirectToRoute('app_admin_menu_index');
    }

    private function form(MenuItem $item, Request $request, string $heading): Response
    {
        $form = $this->createForm(MenuItemType::class, $item)->handleRequest($request);

        if ($form->isSubmitted() && $item->getPage() === null && $item->getUrl() === null) {
            $form->addError(new FormError('Bitte eine veröffentlichte Seite oder eine externe Adresse auswählen.'));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($item->getPage() !== null) {
                $item->setUrl(null);
            }
            if ($item->getId() === null) {
                $this->entityManager->persist($item);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Der Menüpunkt wurde gespeichert.');

            return $this->redirectToRoute('app_admin_menu_index');
        }

        return $this->render('admin/menu/form.html.twig', [
            'form' => $form,
            'heading' => $heading,
        ]);
    }
}
