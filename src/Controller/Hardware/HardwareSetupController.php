<?php

declare(strict_types=1);

namespace App\Controller\Hardware;

use App\Entity\Hardware\HardwareProduct;
use App\Entity\User;
use App\Hardware\HardwareSetupService;
use App\Repository\Hardware\HardwareCommunitySetupRepository;
use App\Repository\Hardware\HardwareProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/account/gaming/hardware')]
#[IsGranted('ROLE_USER')]
final class HardwareSetupController extends AbstractController
{
    public function __construct(
        private readonly HardwareProductRepository $products,
        private readonly HardwareCommunitySetupRepository $setups,
        private readonly HardwareSetupService $setupService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/setups', name: 'app_gaming_hardware_account_setups', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('products', EntityType::class, [
                'class' => HardwareProduct::class,
                'choices' => $this->products->publishedChoices(),
                'choice_label' => static fn (HardwareProduct $product): string => $product->getManufacturer().' '.$product->getName(),
                'multiple' => true,
                'expanded' => false,
                'attr' => ['size' => 8],
                'constraints' => [new Assert\Count(min: 1, max: 8)],
            ])
            ->add('notes', TextareaType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 5000)]])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $owner = $this->getUser();
            $selected = $form->get('products')->getData();
            $notes = $form->get('notes')->getData();
            if (!$owner instanceof User || !is_iterable($selected) || !is_string($notes)) {
                throw $this->createAccessDeniedException();
            }
            $products = [];
            foreach ($selected as $product) {
                if ($product instanceof HardwareProduct) { $products[] = $product; }
            }
            try {
                $setup = $this->setupService->createPending($owner, $products, $notes);
                $this->entityManager->persist($setup);
                $this->entityManager->flush();
                $this->addFlash('success', 'Dein Setup wurde eingereicht und bleibt bis zur Moderation privat.');
                return $this->redirectToRoute('app_gaming_hardware_account_setups');
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        $owner = $this->getUser();
        if (!$owner instanceof User) { throw $this->createAccessDeniedException(); }

        return $this->render('@Hardware/account/setups.html.twig', [
            'form' => $form,
            'setups' => $this->setups->forOwner($owner),
        ]);
    }
}
