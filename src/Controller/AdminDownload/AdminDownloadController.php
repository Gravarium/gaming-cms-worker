<?php

declare(strict_types=1);

namespace App\Controller\AdminDownload;

use App\Downloads\DownloadModuleAvailability;
use App\Downloads\DownloadPrivateStorage;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/downloads')]
#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminDownloadController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DownloadPrivateStorage $storage,
        private readonly DownloadModuleAvailability $availability,
    ) {
    }

    #[Route('/{id}/upload', name: 'app_admin_download_upload_form', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function uploadForm(DownloadPackage $package): Response
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/download/upload.html.twig', ['package' => $package]);
    }

    #[Route('/{id}/upload', name: 'app_admin_download_upload', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function upload(DownloadPackage $package, Request $request): Response
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('download-upload-'.$package->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw $this->createNotFoundException();
        }

        try {
            $version = trim($request->request->getString('version'));
            $stored = $this->storage->store($file);
            $record = (new DownloadVersion(
                $package,
                $version,
                $stored['filename'],
                $stored['sha256'],
                $stored['reference'],
            ))->markScan($stored['scan']);
            $this->em->persist($record);
            $this->em->flush();
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return new Response(
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return $this->redirectToRoute('app_download_show', ['slug' => $package->getSlug()]);
    }
}
