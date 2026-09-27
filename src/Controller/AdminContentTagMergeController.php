<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Form\ContentTagMergeType;
use App\Repository\ContentTagRepository;
use App\Service\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/tags')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentTagMergeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentTagRepository $tags,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/{id}/merge', name: 'app_admin_content_tag_merge', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function merge(ContentTag $sourceTag, Request $request): Response
    {
        $sourceId = $sourceTag->getId();
        if ($sourceId === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ContentTagMergeType::class, [], [
            'source_tag' => $sourceTag,
            'csrf_token_id' => 'content-tag-merge-'.$sourceId,
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $targetTag = $form->get('target')->getData();
            $targetId = $targetTag instanceof ContentTag ? $targetTag->getId() : null;

            if (!$targetTag instanceof ContentTag || $targetId === null || $targetId === $sourceId) {
                $form->get('target')->addError(new FormError('Bitte wähle einen anderen vorhandenen Tag.'));
            } else {
                $movedCount = $this->mergeEntries($sourceTag, $targetTag, $sourceId, $targetId);
                $this->addFlash('success', sprintf('%d Inhalte wurden dem Ziel-Tag zugeordnet; der Quell-Tag wurde entfernt.', $movedCount));

                return $this->redirectToRoute('app_admin_content_tag_index');
            }
        }

        $response = $this->render('admin/content_tag/merge.html.twig', [
            'sourceTag' => $sourceTag,
            'entryCount' => $this->entryCount($sourceId),
            'targetCount' => max(0, $this->tags->count([]) - 1),
            'form' => $form->createView(),
        ]);

        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    private function mergeEntries(ContentTag $sourceTag, ContentTag $targetTag, int $sourceId, int $targetId): int
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($sourceTag, $targetTag, $sourceId, $targetId): int {
            $lockOrder = $sourceId < $targetId ? [$sourceTag, $targetTag] : [$targetTag, $sourceTag];
            foreach ($lockOrder as $tag) {
                $entityManager->lock($tag, LockMode::PESSIMISTIC_WRITE);
            }

            $entries = $entityManager->createQueryBuilder()
                ->select('entry')
                ->from(ContentEntry::class, 'entry')
                ->innerJoin('entry.tags', 'tag')
                ->andWhere('tag.id = :sourceId')
                ->setParameter('sourceId', $sourceId)
                ->orderBy('entry.id', 'ASC')
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getResult();

            $movedCount = 0;
            foreach ($entries as $entry) {
                if (!$entry instanceof ContentEntry) {
                    throw new LogicException('Unexpected content entry result.');
                }

                $entry->addTag($targetTag);
                $entry->removeTag($sourceTag);
                ++$movedCount;
            }

            $this->audit->record(
                'content_tag.merge',
                ContentTag::class,
                $sourceId,
                'Content-Tags zusammengeführt.',
                [
                    'sourceTagId' => $sourceId,
                    'targetTagId' => $targetId,
                    'movedCount' => $movedCount,
                ],
            );

            $entityManager->remove($sourceTag);
            $entityManager->flush();

            return $movedCount;
        });
    }

    private function entryCount(int $sourceId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(entry.id)')
            ->from(ContentEntry::class, 'entry')
            ->innerJoin('entry.tags', 'tag')
            ->andWhere('tag.id = :sourceId')
            ->setParameter('sourceId', $sourceId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
