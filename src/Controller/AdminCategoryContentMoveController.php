<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Form\CategoryContentMoveType;
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

#[Route('/admin/categories')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminCategoryContentMoveController extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ContentEntryRepository $entries,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/{id}/move-content', name: 'app_admin_category_move_content', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function move(Category $source, Request $request): Response
    {
        $form = $this->createForm(CategoryContentMoveType::class, null, ['source_category' => $source])
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $target = $form->get('targetCategory')->getData();
            if (!$target instanceof Category || $target->getId() === $source->getId()) {
                $form->get('targetCategory')->addError(new FormError('Bitte wähle eine andere vorhandene Zielkategorie.'));
            } else {
                $movedCount = 0;
                $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($source, $target, &$movedCount): void {
                    $entries = $this->entries->findBy(['category' => $source], ['id' => 'ASC']);
                    $movedCount = count($entries);
                    if ($movedCount === 0) {
                        return;
                    }

                    foreach ($entries as $entry) {
                        $entry->setCategory($target);
                    }

                    $this->audit->record(
                        'content_category.entries_reassigned',
                        $source,
                        $source->getId(),
                        'Content-Einträge einer Kategorie wurden verschoben.',
                        ['targetCategoryId' => $target->getId(), 'movedCount' => $movedCount],
                    );
                    $entityManager->flush();
                });

                if ($movedCount === 0) {
                    $this->addFlash('error', 'Die Kategorie enthält inzwischen keine Inhalte mehr. Es wurde nichts geändert.');
                } else {
                    $this->addFlash('success', sprintf('%d Inhalte wurden in die gewählte Kategorie verschoben.', $movedCount));
                }

                return $this->redirectToRoute('app_admin_category_index');
            }
        }

        $response = $this->render('admin/category/move_content.html.twig', [
            'form' => $form,
            'source' => $source,
            'entryCount' => $this->entries->count(['category' => $source]),
            'targetCount' => max(0, $this->categories->count([]) - 1),
        ]);

        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }
}
