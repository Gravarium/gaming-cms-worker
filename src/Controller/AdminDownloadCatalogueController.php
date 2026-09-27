<?php

declare(strict_types=1);

namespace App\Controller;

use App\Downloads\DownloadModuleAvailability;
use App\Entity\Download\DownloadPackage;
use App\Form\DownloadCatalogue\DownloadPackageInput;
use App\Form\DownloadCatalogue\DownloadPackageType;
use App\Repository\Download\DownloadPackageRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/downloads')]
#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminDownloadCatalogueController extends AbstractController
{
    public function __construct(
        private readonly DownloadPackageRepository $packages,
        private readonly EntityManagerInterface $entityManager,
        private readonly DownloadModuleAvailability $availability,
    ) {
    }

    #[Route('', name: 'app_admin_download_catalogue_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();

        return $this->render('admin/download_catalogue/index.html.twig', [
            'packages' => $this->packages->findBy([], ['title' => 'ASC', 'id' => 'ASC'], 200),
        ]);
    }

    #[Route('/new', name: 'app_admin_download_catalogue_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->assertAvailable();

        $input = new DownloadPackageInput();
        $form = $this->createForm(DownloadPackageType::class, $input, [
            'create_mode' => true,
            'validation_groups' => ['Default', 'create'],
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $slug = trim($input->slug);
            $slugError = false;
            if ($this->packages->findOneBy(['slug' => $slug]) instanceof DownloadPackage) {
                $form->get('slug')->addError(new FormError('A package with this slug already exists.'));
                $slugError = true;
            } else {
                try {
                    $package = (new DownloadPackage(trim($input->title), $slug, $input->type))
                        ->setVisibility($input->visibility)
                        ->setEnabled($input->enabled);
                    $this->entityManager->persist($package);
                    $this->entityManager->flush();
                } catch (UniqueConstraintViolationException) {
                    $form->get('slug')->addError(new FormError('A package with this slug already exists.'));
                    $slugError = true;
                }
            }

            if (!$slugError) {
                $this->addFlash('success', 'Das Download-Paket wurde angelegt.');

                return $this->redirectToRoute('app_admin_download_catalogue_index');
            }
        }

        return $this->formResponse($form, true);
    }

    #[Route(
        '/{id}/edit',
        name: 'app_admin_download_catalogue_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(DownloadPackage $package, Request $request): Response
    {
        $this->assertAvailable();

        $input = new DownloadPackageInput();
        $input->visibility = $package->getVisibility();
        $input->enabled = $package->isEnabled();

        $form = $this->createForm(DownloadPackageType::class, $input, [
            'create_mode' => false,
            'validation_groups' => ['Default', 'edit'],
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $package
                ->setVisibility($input->visibility)
                ->setEnabled($input->enabled);
            $this->entityManager->flush();
            $this->addFlash('success', 'Die Paket-Einstellungen wurden gespeichert.');

            return $this->redirectToRoute('app_admin_download_catalogue_index');
        }

        return $this->formResponse($form, false, $package);
    }

    private function assertAvailable(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * @param FormInterface<DownloadPackageInput> $form
     */
    private function formResponse(
        FormInterface $form,
        bool $creating,
        ?DownloadPackage $package = null,
    ): Response {
        $response = $this->render('admin/download_catalogue/form.html.twig', [
            'form' => $form->createView(),
            'creating' => $creating,
            'package' => $package,
        ]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }
}
