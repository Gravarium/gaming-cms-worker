<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MenuItem;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/menu/order', name: 'app_admin_menu_order_')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminMenuOrderController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/menu/order.html.twig', [
            'items' => $this->orderedItems($this->entityManager, false),
        ]);
    }

    #[Route(
        '/{id}/{direction}',
        name: 'move',
        requirements: ['id' => '[1-9][0-9]*', 'direction' => 'up|down'],
        methods: ['POST'],
    )]
    public function move(int $id, string $direction, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(
            'menu-order-'.$id.'-'.$direction,
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }

        $moved = false;
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($id, $direction, &$moved): void {
            $items = $this->orderedItems($entityManager, true);
            $index = null;

            foreach ($items as $currentIndex => $item) {
                if ($item->getId() === $id) {
                    $index = $currentIndex;
                    break;
                }
            }

            if ($index === null) {
                throw $this->createNotFoundException();
            }

            $neighborIndex = $index + ($direction === 'up' ? -1 : 1);
            if (!isset($items[$neighborIndex])) {
                return;
            }

            $movingItem = $items[$index];
            array_splice($items, $index, 1);
            array_splice($items, $neighborIndex, 0, [$movingItem]);

            foreach ($items as $position => $item) {
                $item->setPosition($position);
            }

            $entityManager->flush();
            $moved = true;
        });

        if ($moved) {
            $this->addFlash('success', 'Die Menüreihenfolge wurde gespeichert.');
        } else {
            $this->addFlash('info', 'Der Menüpunkt steht bereits an diesem Ende.');
        }

        return $this->redirectToRoute('app_admin_menu_order_index');
    }

    /**
     * @return list<MenuItem>
     */
    private function orderedItems(EntityManagerInterface $entityManager, bool $forUpdate): array
    {
        $query = $entityManager->createQueryBuilder()
            ->select('item')
            ->from(MenuItem::class, 'item')
            ->orderBy('item.position', 'ASC')
            ->addOrderBy('item.id', 'ASC')
            ->getQuery();

        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        /** @var list<MenuItem> $items */
        $items = $query->getResult();

        return $items;
    }
}
