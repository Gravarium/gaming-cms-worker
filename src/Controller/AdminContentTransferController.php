<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentTransfer\ContentTransferArchive;
use App\ContentTransfer\ContentTransferUpload;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Form\ContentTransferType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/transfer', name: 'app_admin_content_transfer_')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentTransferController extends AbstractController
{
    public function __construct(
        private readonly ContentTransferArchive $archive,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderIndex($this->importForm());
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $form = $this->importForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderIndex($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $upload = $form->getData();
        if ($upload->file === null) {
            $form->get('file')->addError(new FormError('Bitte wähle ein JSON-Bundle aus.'));
            return $this->renderIndex($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $author = $this->getUser();
        if (!$author instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $count = $this->archive->import($upload->file->getContent(), $author);
        } catch (\InvalidArgumentException) {
            $form->addError(new FormError('Das Bundle ist ungültig, unvollständig oder enthält nicht unterstützte Inhalte.'));
            return $this->renderIndex($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', sprintf('%d Inhalte wurden als Entwürfe importiert.', $count));

        return $this->privateResponse($this->redirectToRoute('app_admin_content_transfer_index'));
    }

    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('content-transfer-export', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }

        try {
            $entryIds = $this->parseSelection($request->request->all()['ids'] ?? null);
            $json = $this->archive->export($entryIds);
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültige Exportauswahl.', Response::HTTP_BAD_REQUEST));
        }

        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'gaming-cms-content-bundle.json',
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $this->privateResponse($response);
    }

    private function importForm(): FormInterface
    {
        return $this->createForm(ContentTransferType::class, new ContentTransferUpload(), [
            'action' => $this->generateUrl('app_admin_content_transfer_import'),
            'method' => 'POST',
        ]);
    }

    /** @param FormInterface<ContentTransferUpload> $form */
    private function renderIndex(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $statuses = [
            ContentEntry::STATUS_DRAFT,
            ContentEntry::STATUS_REVIEW,
            ContentEntry::STATUS_SCHEDULED,
            ContentEntry::STATUS_PUBLISHED,
            ContentEntry::STATUS_ARCHIVED,
        ];
        /** @var list<ContentEntry> $entries */
        $entries = $this->entityManager->getRepository(ContentEntry::class)->findBy(
            ['status' => $statuses],
            ['updatedAt' => 'DESC', 'id' => 'DESC'],
            ContentTransferArchive::MAX_ENTRIES,
        );

        $response = $this->render('@content_transfer/index.html.twig', [
            'entries' => $entries,
            'form' => $form->createView(),
            'exportToken' => $this->csrfTokenManager->getToken('content-transfer-export')->getValue(),
            'maxEntries' => ContentTransferArchive::MAX_ENTRIES,
        ]);
        $response->setStatusCode($status);

        return $this->privateResponse($response);
    }

    /** @return list<int> */
    private function parseSelection(mixed $rawIds): array
    {
        if (!is_array($rawIds) || !array_is_list($rawIds) || $rawIds === [] || count($rawIds) > ContentTransferArchive::MAX_ENTRIES) {
            throw new \InvalidArgumentException('Die Auswahl ist ungültig.');
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            if (!is_string($rawId) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $rawId) !== 1) {
                throw new \InvalidArgumentException('Die Auswahl ist ungültig.');
            }
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id) || in_array($id, $ids, true)) {
                throw new \InvalidArgumentException('Die Auswahl ist ungültig.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
