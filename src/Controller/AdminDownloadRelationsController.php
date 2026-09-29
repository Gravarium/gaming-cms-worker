<?php

declare(strict_types=1);

namespace App\Controller;

use App\Downloads\DownloadModuleAvailability;
use App\Downloads\Relations\DownloadReleaseRelationManager;
use App\Entity\Download\DownloadDependency;
use App\Entity\Download\DownloadMirror;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Form\DownloadRelations\DownloadDependencyInput;
use App\Form\DownloadRelations\DownloadDependencyType;
use App\Form\DownloadRelations\DownloadMirrorInput;
use App\Form\DownloadRelations\DownloadMirrorType;
use App\Repository\Download\DownloadDependencyRepository;
use App\Repository\Download\DownloadMirrorRepository;
use App\Repository\Download\DownloadVersionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/downloads', name: 'app_admin_download_relations_')]
#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminDownloadRelationsController extends AbstractController
{
    public function __construct(
        private readonly DownloadModuleAvailability $availability,
        private readonly DownloadVersionRepository $versions,
        private readonly DownloadDependencyRepository $dependencies,
        private readonly DownloadMirrorRepository $mirrors,
        private readonly DownloadReleaseRelationManager $relations,
    ) {
    }

    #[Route('/{package}/relations', name: 'package', requirements: ['package' => '\d+'], methods: ['GET'])]
    public function package(DownloadPackage $package): Response
    {
        $this->assertAvailable();

        return $this->privateResponse($this->render('@DownloadReleaseMetadata/admin/package_relations.html.twig', [
            'package' => $package,
            'versions' => $this->versions->forPackage($package),
        ]));
    }

    #[Route('/versions/{version}/relations', name: 'version', requirements: ['version' => '\d+'], methods: ['GET'])]
    public function version(DownloadVersion $version): Response
    {
        $this->assertAvailable();

        return $this->privateResponse($this->render('@DownloadReleaseMetadata/admin/release_relations.html.twig', [
            'version' => $version,
            'dependencies' => $this->dependencies->forVersion($version),
            'mirrors' => $this->mirrors->forVersion($version),
        ]));
    }

    #[Route('/versions/{version}/dependencies/new', name: 'dependency_new', requirements: ['version' => '\d+'], methods: ['GET', 'POST'])]
    public function newDependency(DownloadVersion $version, Request $request): Response
    {
        $this->assertAvailable();

        $input = new DownloadDependencyInput();
        $form = $this->createForm(DownloadDependencyType::class, $input, [
            'source_package' => $version->getPackage(),
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if (!$input->targetPackage instanceof DownloadPackage) {
                    throw new \DomainException('Wähle ein Zielpaket aus.');
                }

                $this->relations->addDependency(
                    $version,
                    $input->targetPackage,
                    $input->kind,
                    $input->constraintExpression,
                );

                return $this->redirectToRoute('app_admin_download_relations_version', [
                    'version' => $version->getId(),
                ]);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->relationFormResponse(
            '@DownloadReleaseMetadata/admin/dependency_form.html.twig',
            $version,
            $form,
        );
    }

    #[Route('/versions/{version}/mirrors/new', name: 'mirror_new', requirements: ['version' => '\d+'], methods: ['GET', 'POST'])]
    public function newMirror(DownloadVersion $version, Request $request): Response
    {
        $this->assertAvailable();

        $input = new DownloadMirrorInput();
        $form = $this->createForm(DownloadMirrorType::class, $input)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->relations->addMirror($version, $input->url, $input->trusted);

                return $this->redirectToRoute('app_admin_download_relations_version', [
                    'version' => $version->getId(),
                ]);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->relationFormResponse(
            '@DownloadReleaseMetadata/admin/mirror_form.html.twig',
            $version,
            $form,
        );
    }

    #[Route('/versions/{version}/dependencies/{dependency}/delete', name: 'dependency_delete', requirements: ['version' => '\d+', 'dependency' => '\d+'], methods: ['POST'])]
    public function deleteDependency(
        DownloadVersion $version,
        DownloadDependency $dependency,
        Request $request,
    ): Response {
        $this->assertAvailable();
        if ($dependency->getVersion()->getId() !== $version->getId()) {
            throw $this->createNotFoundException();
        }

        $tokenId = 'download-dependency-delete-'.$version->getId().'-'.$dependency->getId();
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->relations->removeDependency($dependency);

        return $this->redirectToRoute('app_admin_download_relations_version', [
            'version' => $version->getId(),
        ]);
    }

    #[Route('/versions/{version}/mirrors/{mirror}/delete', name: 'mirror_delete', requirements: ['version' => '\d+', 'mirror' => '\d+'], methods: ['POST'])]
    public function deleteMirror(
        DownloadVersion $version,
        DownloadMirror $mirror,
        Request $request,
    ): Response {
        $this->assertAvailable();
        if ($mirror->getVersion()->getId() !== $version->getId()) {
            throw $this->createNotFoundException();
        }

        $tokenId = 'download-mirror-delete-'.$version->getId().'-'.$mirror->getId();
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->relations->removeMirror($mirror);

        return $this->redirectToRoute('app_admin_download_relations_version', [
            'version' => $version->getId(),
        ]);
    }

    /**
     * @param FormInterface<DownloadDependencyInput>|FormInterface<DownloadMirrorInput> $form
     */
    private function relationFormResponse(string $template, DownloadVersion $version, FormInterface $form): Response
    {
        $response = $this->render($template, [
            'version' => $version,
            'form' => $form->createView(),
        ]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->privateResponse($response);
    }

    private function assertAvailable(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
