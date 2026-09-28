<?php

declare(strict_types=1);

namespace App\Controller\AdminDownload;

use App\Downloads\DownloadModuleAvailability;
use App\Downloads\DownloadPrivateStorage;
use App\Downloads\DownloadScanService;
use App\Downloads\DownloadStorageUnavailable;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Form\DownloadCatalogue\DownloadVersionInput;
use App\Form\DownloadCatalogue\DownloadVersionType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
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
        $form = $this->createForm(DownloadVersionType::class, new DownloadVersionInput());

        return $this->uploadFormResponse($package, $form, Response::HTTP_OK);
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

        $input = new DownloadVersionInput();
        $form = $this->createForm(DownloadVersionType::class, $input)->handleRequest($request);
        if (!$form->isSubmitted()) {
            $form->addError(new FormError('Sende das Uploadformular erneut ab.'));

            return $this->uploadFormResponse($package, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$form->isValid()) {
            return $this->uploadFormResponse($package, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $input->file;
        if (!$file instanceof UploadedFile) {
            $form->get('file')->addError(new FormError('Wähle eine Datei aus.'));

            return $this->uploadFormResponse($package, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $this->em->getRepository(DownloadVersion::class)->findOneBy([
            'package' => $package,
            'version' => $input->version,
        ]);
        if ($existing instanceof DownloadVersion) {
            $form->get('version')->addError(new FormError('Diese Version existiert bereits für dieses Paket.'));

            return $this->uploadFormResponse($package, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{reference: string, staged_reference: string, filename: string, sha256: string, scan: string}|null $stored */
        $stored = null;
        $connection = $this->em->getConnection();

        try {
            $stored = $this->storage->store($file);
            $connection->beginTransaction();

            $record = (new DownloadVersion(
                $package,
                $input->version,
                $stored['filename'],
                $stored['sha256'],
                $stored['reference'],
            ))
                ->setCompatibility($input->compatibilityValues())
                ->setChangelog($input->changelog)
                ->markScan($stored['scan']);

            $this->em->persist($record);
            $this->em->flush();
            $this->storage->finalize($stored);
            $connection->commit();
        } catch (\InvalidArgumentException $exception) {
            $this->rollbackAndDiscard($connection, $stored);
            $form->get('version')->addError(new FormError($exception->getMessage()));

            return $this->uploadFormResponse($package, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\DomainException|DownloadStorageUnavailable $exception) {
            $this->rollbackAndDiscard($connection, $stored);
            $form->get('file')->addError(new FormError($exception->getMessage()));

            return $this->uploadFormResponse($package, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
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

    /**
     * @param FormInterface<DownloadVersionInput> $form
     */
    private function uploadFormResponse(
        DownloadPackage $package,
        FormInterface $form,
        int $status,
    ): Response {
        $response = $this->render('@DownloadReleaseMetadata/admin/upload.html.twig', [
            'package' => $package,
            'form' => $form->createView(),
        ]);
        $response->setStatusCode($status);

        return $response;
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
