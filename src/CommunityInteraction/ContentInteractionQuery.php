<?php

declare(strict_types=1);

namespace App\CommunityInteraction;

use App\Community\Interaction\ReportRecord;
use App\Entity\Community\CommunityComment;
use App\Entity\Community\CommunityReaction;
use App\Entity\Community\CommunityReport;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ContentInteractionQuery
{
    private const COMMENT_PAGE_SIZE = 25;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function publishedEntry(string $slug): ?ContentEntry
    {
        $entry = $this->entityManager->getRepository(ContentEntry::class)->findOneBy(['slug' => $slug]);

        return $entry instanceof ContentEntry && $entry->isPublished() ? $entry : null;
    }

    public function entry(int $id): ?ContentEntry
    {
        $entry = $this->entityManager->find(ContentEntry::class, $id);

        return $entry instanceof ContentEntry ? $entry : null;
    }

    /** @return list<CommunityComment> */
    public function publicComments(int $targetId, int $page): array
    {
        $comments = $this->entityManager->createQueryBuilder()
            ->select('comment', 'author', 'parent', 'parentAuthor')
            ->from(CommunityComment::class, 'comment')
            ->join('comment.author', 'author')
            ->leftJoin('comment.parent', 'parent')
            ->leftJoin('parent.author', 'parentAuthor')
            ->andWhere('comment.targetType = :targetType')
            ->andWhere('comment.targetId = :targetId')
            ->andWhere('comment.deletedAt IS NULL')
            ->setParameter('targetType', 'content')
            ->setParameter('targetId', $targetId)
            ->orderBy('comment.createdAt', 'DESC')
            ->addOrderBy('comment.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * self::COMMENT_PAGE_SIZE))
            ->setMaxResults(self::COMMENT_PAGE_SIZE)
            ->getQuery()
            ->getResult();

        /** @var list<CommunityComment> $comments */
        return $comments;
    }

    public function countPublicComments(int $targetId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(comment.id)')
            ->from(CommunityComment::class, 'comment')
            ->andWhere('comment.targetType = :targetType')
            ->andWhere('comment.targetId = :targetId')
            ->andWhere('comment.deletedAt IS NULL')
            ->setParameter('targetType', 'content')
            ->setParameter('targetId', $targetId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function publicComment(int $commentId, int $targetId): ?CommunityComment
    {
        $comment = $this->entityManager->createQueryBuilder()
            ->select('comment', 'author', 'parent')
            ->from(CommunityComment::class, 'comment')
            ->join('comment.author', 'author')
            ->leftJoin('comment.parent', 'parent')
            ->andWhere('comment.id = :commentId')
            ->andWhere('comment.targetType = :targetType')
            ->andWhere('comment.targetId = :targetId')
            ->andWhere('comment.deletedAt IS NULL')
            ->setParameter('commentId', $commentId)
            ->setParameter('targetType', 'content')
            ->setParameter('targetId', $targetId)
            ->getQuery()
            ->getOneOrNullResult();

        return $comment instanceof CommunityComment ? $comment : null;
    }

    /**
     * @return list<array{entry: ContentEntry, commentCount: int}>
     */
    public function recentPublicDiscussions(int $limit = 6): array
    {
        $entriesQuery = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(ContentEntry::class, 'entry')
            ->andWhere('entry.status = :status')
            ->andWhere('entry.publishedAt IS NOT NULL')
            ->andWhere('entry.publishedAt <= :now')
            ->andWhere('entry.unlisted = false')
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('entry.pinned', 'DESC')
            ->addOrderBy('entry.publishedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setMaxResults(max(1, min(12, $limit)))
            ->getQuery()
            ->getResult();

        /** @var list<ContentEntry> $entries */
        $entries = $entriesQuery;
        $entryIds = array_values(array_filter(array_map(
            static fn (ContentEntry $entry): ?int => $entry->getId(),
            $entries,
        )));
        $counts = $this->commentCounts($entryIds);

        return array_map(
            static fn (ContentEntry $entry): array => [
                'entry' => $entry,
                'commentCount' => $counts[$entry->getId() ?? 0] ?? 0,
            ],
            $entries,
        );
    }

    /** @param list<int> $entryIds
     * @return array<int, int>
     */
    public function commentCounts(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('comment.targetId AS targetId', 'COUNT(comment.id) AS total')
            ->from(CommunityComment::class, 'comment')
            ->andWhere('comment.targetType = :targetType')
            ->andWhere('comment.targetId IN (:targetIds)')
            ->andWhere('comment.deletedAt IS NULL')
            ->setParameter('targetType', 'content')
            ->setParameter('targetIds', $entryIds)
            ->groupBy('comment.targetId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['targetId']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @param list<int> $commentIds
     * @return array<int, array<string, int>>
     */
    public function reactionCounts(array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('identity(reaction.comment) AS commentId', 'reaction.reaction AS reaction', 'COUNT(reaction.id) AS total')
            ->from(CommunityReaction::class, 'reaction')
            ->andWhere('identity(reaction.comment) IN (:commentIds)')
            ->setParameter('commentIds', $commentIds)
            ->groupBy('reaction.comment')
            ->addGroupBy('reaction.reaction')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['commentId']][(string) $row['reaction']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @param list<int> $commentIds
     * @return array<int, list<string>>
     */
    public function userReactions(array $commentIds, User $user): array
    {
        if ($commentIds === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('identity(reaction.comment) AS commentId', 'reaction.reaction AS reaction')
            ->from(CommunityReaction::class, 'reaction')
            ->andWhere('identity(reaction.comment) IN (:commentIds)')
            ->andWhere('reaction.user = :user')
            ->setParameter('commentIds', $commentIds)
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $reactions = [];
        foreach ($rows as $row) {
            $reactions[(int) $row['commentId']][] = (string) $row['reaction'];
        }

        return $reactions;
    }

    /** @return list<string> */
    public function targetReactionTypes(int $targetId, User $user): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT reaction.reaction AS reaction')
            ->from(CommunityReaction::class, 'reaction')
            ->join('reaction.comment', 'comment')
            ->andWhere('comment.targetType = :targetType')
            ->andWhere('comment.targetId = :targetId')
            ->andWhere('reaction.user = :user')
            ->setParameter('targetType', 'content')
            ->setParameter('targetId', $targetId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['reaction'], $rows));
    }

    public function reaction(CommunityComment $comment, User $user, string $value): ?CommunityReaction
    {
        $reaction = $this->entityManager->getRepository(CommunityReaction::class)->findOneBy([
            'comment' => $comment,
            'user' => $user,
            'reaction' => $value,
        ]);

        return $reaction instanceof CommunityReaction ? $reaction : null;
    }

    public function activeReport(CommunityComment $comment, User $reporter): ?CommunityReport
    {
        $report = $this->entityManager->getRepository(CommunityReport::class)->createQueryBuilder('report')
            ->andWhere('report.comment = :comment')
            ->andWhere('report.reporter = :reporter')
            ->andWhere('report.status IN (:statuses)')
            ->setParameter('comment', $comment)
            ->setParameter('reporter', $reporter)
            ->setParameter('statuses', [ReportRecord::STATUS_OPEN, ReportRecord::STATUS_REVIEWING])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $report instanceof CommunityReport ? $report : null;
    }

    /** @return list<CommunityReport> */
    public function reportsForModeration(): array
    {
        $reports = $this->entityManager->createQueryBuilder()
            ->select('report', 'comment', 'author', 'reporter')
            ->from(CommunityReport::class, 'report')
            ->join('report.comment', 'comment')
            ->join('comment.author', 'author')
            ->join('report.reporter', 'reporter')
            ->andWhere('comment.targetType = :targetType')
            ->andWhere('report.status IN (:statuses)')
            ->setParameter('targetType', 'content')
            ->setParameter('statuses', [ReportRecord::STATUS_OPEN, ReportRecord::STATUS_REVIEWING])
            ->orderBy('report.createdAt', 'DESC')
            ->addOrderBy('report.id', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        /** @var list<CommunityReport> $reports */
        return $reports;
    }

    /** @return list<CommunityComment> */
    public function hiddenContentComments(): array
    {
        $comments = $this->entityManager->createQueryBuilder()
            ->select('comment', 'author')
            ->from(CommunityComment::class, 'comment')
            ->join('comment.author', 'author')
            ->andWhere('comment.targetType = :targetType')
            ->andWhere('comment.deletedAt IS NOT NULL')
            ->setParameter('targetType', 'content')
            ->orderBy('comment.id', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        /** @var list<CommunityComment> $comments */
        return $comments;
    }
}
