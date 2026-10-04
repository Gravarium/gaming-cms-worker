<?php

declare(strict_types=1);

namespace App\Controller\Download;

use App\Downloads\DownloadAuthorization;
use App\Downloads\DownloadModuleAvailability;
use App\Downloads\DownloadPrivateStorage;
use App\Downloads\DownloadStorageUnavailable;
use App\Entity\Download\DownloadDependency;
use App\Entity\Download\DownloadMirror;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Entity\User;
use App\Repository\Download\DownloadDependencyRepository;
use App\Repository\Download\DownloadMirrorRepository;
use App\Repository\Download\DownloadPackageRepository;
use App\Repository\Download\DownloadVersionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/downloads')]
final class DownloadController extends AbstractController
{
    public function __construct(
        private readonly DownloadPackageRepository $packages,
        private readonly DownloadVersionRepository $versions,
        private readonly DownloadDependencyRepository $dependencies,
        private readonly DownloadMirrorRepository $mirrors,
        private readonly DownloadAuthorization $authorization,
        private readonly DownloadPrivateStorage $storage,
        private readonly DownloadModuleAvailability $availability,
    ) {
    }

    #[Route('', name: 'app_download_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();
        $user = $this->user();
        $packages = array_values(array_filter(
            $this->packages->enabledPackages(),
            fn (DownloadPackage $package): bool => $this->authorization->canDownload($package, $user),
        ));

        return $this->render('download/index.html.twig', ['packages' => $packages]);
    }

    #[Route('/{slug}', name: 'app_download_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug): Response
    {
        $this->assertAvailable();
        $package = $this->packages->enabledBySlug($slug);
        if (
            !$package instanceof DownloadPackage
            || !$this->authorization->canDownload($package, $this->user())
        ) {
            throw $this->createNotFoundException();
        }

        $versions = $this->versions->forPackage($package);
        /** @var array<int, array{dependencies: list<DownloadDependency>, mirrors: list<DownloadMirror>}> $relationsByVersion */
        $relationsByVersion = [];
        foreach ($versions as $version) {
            $versionId = $version->getId();
            if ($versionId !== null) {
                $relationsByVersion[$versionId] = ['dependencies' => [], 'mirrors' => []];
            }
        }

        foreach ($this->dependencies->forVersions($versions) as $dependency) {
            $versionId = $dependency->getVersion()->getId();
            $target = $dependency->getTargetPackage();
            if (
                $versionId === null
                || !isset($relationsByVersion[$versionId])
                || !$dependency->getVersion()->isDeliverable()
                || !$this->authorization->canDownload($target, $this->user())
            ) {
                continue;
            }

            $relationsByVersion[$versionId]['dependencies'][] = $dependency;
        }

        foreach ($this->mirrors->forVersions($versions) as $mirror) {
            $versionId = $mirror->getVersion()->getId();
            if (
                $versionId === null
                || !isset($relationsByVersion[$versionId])
                || !$mirror->getVersion()->isDeliverable()
                || !$mirror->isTrusted()
            ) {
                continue;
            }

            $relationsByVersion[$versionId]['mirrors'][] = $mirror;
        }

        $response = $this->render('@DownloadReleaseMetadata/download/show.html.twig', [
            'package' => $package,
            'versions' => $versions,
            'relationsByVersion' => $relationsByVersion,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route(
        '/{slug}/file/{id}',
        name: 'app_download_file',
        requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function deliver(string $slug, DownloadVersion $version): Response
    {
        $this->assertAvailable();
        $package = $version->getPackage();
        if (
            $package->getSlug() !== $slug
            || !$this->authorization->canDownload($package, $this->user())
            || !$version->isDeliverable()
        ) {
            throw $this->createNotFoundException();
        }

        try {
            $path = $this->storage->absolutePath($version->getStorageReference());
        } catch (\DomainException|DownloadStorageUnavailable) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition('attachment', $version->getSafeFilename());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function assertAvailable(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    private function user(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
