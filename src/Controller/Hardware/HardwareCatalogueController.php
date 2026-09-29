<?php

declare(strict_types=1);

namespace App\Controller\Hardware;

use App\Entity\Hardware\HardwareCommunitySetup;
use App\Hardware\HardwareComparisonBuilder;
use App\Repository\Hardware\HardwareBenchmarkMeasurementRepository;
use App\Repository\Hardware\HardwareCommunitySetupRepository;
use App\Repository\Hardware\HardwareProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gaming/hardware')]
final class HardwareCatalogueController extends AbstractController
{
    public function __construct(
        private readonly HardwareProductRepository $products,
        private readonly HardwareBenchmarkMeasurementRepository $measurements,
        private readonly HardwareCommunitySetupRepository $setups,
        private readonly HardwareComparisonBuilder $comparison,
    ) {}

    #[Route('', name: 'app_gaming_hardware_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? '';
        $rawCategory = $parameters['category'] ?? '';
        $rawPage = $parameters['page'] ?? '1';
        $invalidFilter = false;
        if (!is_string($rawQuery) || strlen($rawQuery) > 400 || !mb_check_encoding($rawQuery, 'UTF-8')) {
            $query = '';
            $invalidFilter = true;
        } else {
            $query = mb_substr(trim($rawQuery), 0, 100);
        }
        $categories = $this->products->publicCategories();
        if (!is_string($rawCategory) || strlen($rawCategory) > 320 || ($rawCategory !== '' && !in_array($rawCategory, $categories, true))) {
            $category = '';
            $invalidFilter = true;
        } else {
            $category = $rawCategory;
        }
        if (!is_string($rawPage) || !preg_match('/^[1-9][0-9]{0,5}$/', $rawPage)) {
            $page = 1;
            $invalidFilter = true;
        } else {
            $page = (int) $rawPage;
        }

        $directory = $invalidFilter ? ['products' => [], 'total' => 0] : $this->products->publicDirectory($query, $category, $page);
        $pages = max(1, (int) ceil($directory['total'] / 24));
        if ($page > $pages) {
            $page = $pages;
            $directory = $invalidFilter ? $directory : $this->products->publicDirectory($query, $category, $page);
        }

        return $this->render('@Hardware/public/index.html.twig', [
            'products' => $directory['products'], 'categories' => $categories, 'query' => $query,
            'category' => $category, 'page' => $page, 'pages' => $pages, 'total' => $directory['total'],
            'invalidFilter' => $invalidFilter,
        ]);
    }

    #[Route('/products/{id}', name: 'app_gaming_hardware_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $product = $this->products->publicById($id);
        if ($product === null) { throw $this->createNotFoundException(); }
        $measurements = $this->measurements->publicForProducts([$id]);
        $setups = $this->setups->publicContaining([$id]);
        $referencedIds = [];
        foreach ($setups as $setup) { $referencedIds = [...$referencedIds, ...$setup->getProductIds()]; }
        $visibleProducts = $this->products->publicByIds(array_values(array_unique($referencedIds)));
        $visibleIds = [];
        foreach ($visibleProducts as $visibleProduct) {
            if ($visibleProduct->getId() !== null) { $visibleIds[$visibleProduct->getId()] = true; }
        }
        $setups = array_values(array_filter($setups, static function (HardwareCommunitySetup $setup) use ($visibleIds): bool {
            foreach ($setup->getProductIds() as $productId) { if (!isset($visibleIds[$productId])) { return false; } }
            return true;
        }));

        return $this->render('@Hardware/public/show.html.twig', [
            'product' => $product,
            'measurements' => $measurements,
            'setups' => $setups,
        ]);
    }

    #[Route('/compare', name: 'app_gaming_hardware_compare', methods: ['GET'])]
    public function compare(Request $request): Response
    {
        $rawIds = $request->query->all()['ids'] ?? null;
        $ids = [];
        $invalidSelection = !is_array($rawIds) || count($rawIds) < 2 || count($rawIds) > 4;
        if (is_array($rawIds) && count($rawIds) >= 2 && count($rawIds) <= 4) {
            foreach ($rawIds as $rawId) {
                if (!is_string($rawId) || !preg_match('/^[1-9][0-9]{0,8}$/', $rawId)) {
                    $invalidSelection = true;
                    break;
                }
                $ids[] = (int) $rawId;
            }
            if (count($ids) !== count(array_unique($ids))) { $invalidSelection = true; }
        }
        $products = $invalidSelection ? [] : $this->products->publicByIds($ids);
        if (count($products) !== count($ids)) { $invalidSelection = true; $products = []; }
        $measurements = $invalidSelection ? [] : $this->measurements->publicForProducts($ids);

        return $this->render('@Hardware/public/compare.html.twig', [
            'products' => $products,
            'comparisons' => $invalidSelection ? [] : $this->comparison->build($products, $measurements),
            'invalidSelection' => $invalidSelection,
        ]);
    }
}
