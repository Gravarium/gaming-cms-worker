<?php

declare(strict_types=1);

namespace App\Controller\AdminDownload;

use App\Downloads\DownloadModuleAvailability;
use App\Downloads\DownloadPrivateStorage;
use App\Downloads\DownloadScanService;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use Doctrine\DBAL\Connection;
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
        private readonly DownloadScanService $scanService,
        private readonly DownloadModuleAvailability $availability,
    ) {
    }

    #[Route(
        '/{id}/upload',
        name: 'app_admin_download_upload_form',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function uploadForm(DownloadPackage $package): Response
    {
        $this->assertAvailable();

        return $this->render('admin/download/upload.html.twig', ['package' => $package]);
    }

    #[Route(
        '/{id}/upload',
        name: 'app_admin_download_upload',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function upload(DownloadPackage $package, Request $request): Response
    {
        $this->assertAvailable();
        if (!$this->isCsrfTokenValid(
            'download-upload-'.$package->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw $this->createNotFoundException();
        }

        /** @var array{reference: string, staged_reference: string, filename: string, sha256: string, scan: string}|null $stored */
        $stored = null;
        $connection = $this->em->getConnection();

        try {
            $stored = $this->storage->store($file);
            $connection->beginTransaction();

            $record = (new DownloadVersion(
                $package,
                $request->request->getString('version'),
                $stored['filename'],
                $stored['sha256'],
                $stored['reference'],
            ))->markScan($stored['scan']);

            $this->em->persist($record);
            $this->em->flush();
            $this->storage->finalize($stored);
            $connection->commit();
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->rollbackAndDiscard($connection, $stored);

            return new Response(
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        } catch (\Throwable $exception) {
            $this->rollbackAndDiscard($connection, $stored);

            throw $exception;
        }

        return $this->redirectToRoute('app_download_show', ['slug' => $package->getSlug()]);
    }

    #[Route(
        '/{id}/rescan',
        name: 'app_admin_download_rescan',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function rescan(DownloadVersion $version, Request $request): Response
    {
        $this->assertAvailable();
        if (!$this->isCsrfTokenValid(
            'download-rescan-'.$version->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $status = $this->scanService->rescan($version);
        $this->em->flush();

        if ($status === DownloadVersion::SCAN_REJECTED) {
            return new Response(
                'Download rejected by malware scan.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return $this->redirectToRoute('app_download_show', [
            'slug' => $version->getPackage()->getSlug(),
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * @param array{reference: string, staged_reference: string, filename: string, sha256: string, scan: string}|null $stored
     */
    private function rollbackAndDiscard(Connection $connection, ?array $stored): void
    {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if ($stored !== null) {
            $this->storage->discard($stored);
        }
    }
}
