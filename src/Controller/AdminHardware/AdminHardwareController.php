<?php

declare(strict_types=1);

namespace App\Controller\AdminHardware;

use App\Entity\Hardware\HardwareBenchmarkMeasurement;
use App\Entity\Hardware\HardwareBenchmarkMethodology;
use App\Entity\Hardware\HardwareCommunitySetup;
use App\Entity\Hardware\HardwareProduct;
use App\Entity\Hardware\HardwareProductRevision;
use App\Entity\User;
use App\Hardware\BenchmarkMeasurement;
use App\Hardware\BenchmarkMethodology;
use App\Hardware\HardwareSpecificationSet;
use App\Hardware\ProductRevisionHistory;
use App\Repository\Hardware\HardwareBenchmarkMeasurementRepository;
use App\Repository\Hardware\HardwareBenchmarkMethodologyRepository;
use App\Repository\Hardware\HardwareCommunitySetupRepository;
use App\Repository\Hardware\HardwareProductRepository;
use App\Repository\Hardware\HardwareProductRevisionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/admin/gaming/hardware')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminHardwareController extends AbstractController
{
    public function __construct(
        private readonly HardwareProductRepository $products,
        private readonly HardwareBenchmarkMethodologyRepository $methodologies,
        private readonly HardwareBenchmarkMeasurementRepository $measurements,
        private readonly HardwareCommunitySetupRepository $setups,
        private readonly HardwareProductRevisionRepository $revisions,
        private readonly HardwareSpecificationSet $specifications,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'app_admin_gaming_hardware_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Hardware/admin/index.html.twig', [
            'products' => $this->products->adminDirectory(),
            'methodologies' => $this->methodologies->adminDirectory(),
            'measurements' => $this->measurements->adminDirectory(),
            'pendingSetups' => $this->setups->pending(),
        ]);
    }

    #[Route('/products/new', name: 'app_admin_gaming_hardware_product_new', methods: ['GET', 'POST'])]
    public function newProduct(Request $request): Response
    {
        return $this->productForm(new HardwareProduct(), $request, true);
    }

    #[Route('/products/{id}/edit', name: 'app_admin_gaming_hardware_product_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function editProduct(HardwareProduct $product, Request $request): Response
    {
        return $this->productForm($product, $request, false);
    }

    #[Route('/methodologies/new', name: 'app_admin_gaming_hardware_methodology_new', methods: ['GET', 'POST'])]
    public function newMethodology(Request $request): Response
    {
        $methodology = new HardwareBenchmarkMethodology();
        $form = $this->createFormBuilder($methodology)
            ->add('name', TextType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 180)]])
            ->add('version', TextType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 80)]])
            ->add('procedure', TextareaType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 10_000)]])
            ->add('testSystemJson', TextareaType::class, ['mapped' => false, 'required' => true, 'data' => '{}', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 10_000)]])
            ->add('disclosure', TextareaType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 5000)]])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $testSystem = $this->testSystem((string) $form->get('testSystemJson')->getData());
                new BenchmarkMethodology($methodology->getName(), $methodology->getVersion(), $methodology->getProcedure(), $testSystem, $methodology->getDisclosure());
                $methodology->setTestSystem($testSystem);
                $this->entityManager->persist($methodology);
                $this->entityManager->flush();
                $this->addFlash('success', 'Die Benchmark-Methodik wurde gespeichert.');
                return $this->redirectToRoute('app_admin_gaming_hardware_index');
            } catch (\InvalidArgumentException $exception) {
                $form->get('testSystemJson')->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('@Hardware/admin/methodology_form.html.twig', ['form' => $form, 'methodology' => $methodology]);
    }

    #[Route('/measurements/new', name: 'app_admin_gaming_hardware_measurement_new', methods: ['GET', 'POST'])]
    public function newMeasurement(Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('product', EntityType::class, ['class' => HardwareProduct::class, 'choices' => $this->products->adminDirectory(), 'choice_label' => 'name', 'mapped' => false, 'constraints' => [new Assert\NotNull()]])
            ->add('methodology', EntityType::class, ['class' => HardwareBenchmarkMethodology::class, 'choices' => $this->methodologies->adminDirectory(), 'choice_label' => static fn (HardwareBenchmarkMethodology $item): string => $item->getName().' '.$item->getVersion(), 'mapped' => false, 'constraints' => [new Assert\NotNull()]])
            ->add('series', TextType::class, ['mapped' => false, 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 180)]])
            ->add('value', NumberType::class, ['mapped' => false, 'input' => 'number', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: -1_000_000_000, max: 1_000_000_000)]])
            ->add('unit', TextType::class, ['mapped' => false, 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 32)]])
            ->add('sampleCount', IntegerType::class, ['mapped' => false, 'constraints' => [new Assert\Range(min: 1, max: 1_000_000)]])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $product = $form->get('product')->getData();
            $methodology = $form->get('methodology')->getData();
            $series = $form->get('series')->getData();
            $value = $form->get('value')->getData();
            $unit = $form->get('unit')->getData();
            $sampleCount = $form->get('sampleCount')->getData();
            if (!$product instanceof HardwareProduct || !$methodology instanceof HardwareBenchmarkMethodology || !is_string($series) || (!is_int($value) && !is_float($value)) || !is_string($unit) || !is_int($sampleCount)) {
                $form->addError(new FormError('Benchmark-Daten sind unvollständig.'));
            } else {
                try {
                    $measurement = (new HardwareBenchmarkMeasurement())->setProduct($product)->setMethodology($methodology)
                        ->setSeries($series)->setValue((float) $value)->setUnit($unit)->setSampleCount($sampleCount)->setSourceType('editorial');
                    new BenchmarkMeasurement($product->getId() ?? 0, $measurement->getSeries(), $measurement->getValue(), $measurement->getUnit(), $measurement->getSampleCount(), 'editorial');
                    $user = $this->managerUser();
                    $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($product, $measurement, $user): void {
                        $entityManager->lock($product, LockMode::PESSIMISTIC_WRITE);
                        $entityManager->persist($measurement);
                        $entityManager->flush();
                        $this->appendRevision($product, $user, 'Benchmarkmessung hinzugefügt.');
                        $entityManager->flush();
                    });
                    $this->addFlash('success', 'Die redaktionelle Benchmark-Messung wurde gespeichert.');
                    return $this->redirectToRoute('app_admin_gaming_hardware_index');
                } catch (\InvalidArgumentException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('@Hardware/admin/measurement_form.html.twig', ['form' => $form]);
    }

    #[Route('/setups', name: 'app_admin_gaming_hardware_setups', methods: ['GET'])]
    public function setups(): Response
    {
        return $this->render('@Hardware/admin/setups.html.twig', ['setups' => $this->setups->moderationQueue()]);
    }

    #[Route('/setups/{id}/moderation', name: 'app_admin_gaming_hardware_setup_moderation', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function moderateSetup(HardwareCommunitySetup $setup, Request $request): Response
    {
        $action = $request->request->getString('action');
        if (!in_array($action, ['approve', 'hide'], true)) { throw $this->createNotFoundException(); }
        if (!$this->isCsrfTokenValid('hardware-setup-'.$action.'-'.$setup->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $setup->setModerated($action === 'approve');
        $this->entityManager->flush();
        $this->addFlash('success', $action === 'approve' ? 'Das Community-Setup wurde freigegeben.' : 'Das Community-Setup ist nicht mehr öffentlich.');

        return $this->redirectToRoute('app_admin_gaming_hardware_setups');
    }

    private function productForm(HardwareProduct $product, Request $request, bool $new): Response
    {
        $form = $this->createFormBuilder($product)
            ->add('name', TextType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 180)]])
            ->add('category', TextType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 80)]])
            ->add('manufacturer', TextType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 160)]])
            ->add('disclosure', TextareaType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 5000)]])
            ->add('published', CheckboxType::class, ['required' => false])
            ->add('specificationsJson', TextareaType::class, ['mapped' => false, 'required' => false, 'data' => $this->specifications->encode($product), 'attr' => ['rows' => 12]])
            ->add('revisionSummary', TextType::class, ['mapped' => false, 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 500)]])
            ->getForm();
        $form->handleRequest($request);
        $specifications = null;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $specifications = $this->specifications->decode((string) $form->get('specificationsJson')->getData());
            } catch (\InvalidArgumentException $exception) {
                $form->get('specificationsJson')->addError(new FormError($exception->getMessage()));
            }
        }

        if ($form->isSubmitted() && $form->isValid() && $specifications !== null) {
            $user = $this->managerUser();
            $summary = (string) $form->get('revisionSummary')->getData();
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($product, $new, $user, $summary, $specifications): void {
                if (!$new) { $entityManager->lock($product, LockMode::PESSIMISTIC_WRITE); }
                $this->specifications->replace($product, $specifications);
                $entityManager->persist($product);
                $entityManager->flush();
                $this->appendRevision($product, $user, $summary);
                $entityManager->flush();
            });
            $this->addFlash('success', 'Das Hardware-Produkt und seine Revision wurden gespeichert.');
            return $this->redirectToRoute('app_admin_gaming_hardware_product_edit', ['id' => $product->getId()]);
        }

        return $this->render('@Hardware/admin/product_form.html.twig', [
            'form' => $form,
            'product' => $product,
            'revisions' => $new ? [] : $this->revisions->forProduct($product),
        ]);
    }

    /** @return array<string, string> */
    private function testSystem(string $json): array
    {
        if (strlen($json) > 10_000) { throw new \InvalidArgumentException('Testsystem ist zu groß.'); }
        try { $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $exception) { throw new \InvalidArgumentException('Testsystem muss gültiges JSON sein.', previous: $exception); }
        if (!$decoded instanceof \stdClass || count(get_object_vars($decoded)) < 1 || count(get_object_vars($decoded)) > 100) {
            throw new \InvalidArgumentException('Testsystem muss ein Objekt mit 1 bis 100 Feldern sein.');
        }
        $result = [];
        foreach (get_object_vars($decoded) as $name => $value) {
            if (!is_string($value) || trim($name) === '' || mb_strlen($name) > 120 || trim($value) === '' || mb_strlen($value) > 200) {
                throw new \InvalidArgumentException('Jedes Testsystem-Feld braucht einen Namen und einen Textwert.');
            }
            $result[$name] = trim($value);
        }
        return $result;
    }

    private function appendRevision(HardwareProduct $product, User $actor, string $summary): void
    {
        $actorId = $actor->getId();
        if ($actorId === null) { throw new \LogicException('A persisted manager account is required.'); }
        (new ProductRevisionHistory())->append($actorId, $summary, new \DateTimeImmutable());
        $revision = (new HardwareProductRevision())->setProduct($product)->setActor($actor)
            ->setVersion($this->revisions->nextVersion($product))->setSummary($summary);
        $this->entityManager->persist($revision);
    }

    private function managerUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
