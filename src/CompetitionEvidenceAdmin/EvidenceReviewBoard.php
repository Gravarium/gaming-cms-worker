<?php

declare(strict_types=1);

namespace App\CompetitionEvidenceAdmin;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatchEvidence;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EvidenceReviewBoard
{
    public const PAGE_SIZE = 25;
    public const MAX_RESULTS = 100;
    public const MAX_PAGE = 4;

    /** @var list<string> */
    public const TYPES = [
        CompetitionMatchEvidence::TYPE_SCREENSHOT,
        CompetitionMatchEvidence::TYPE_VIDEO,
        CompetitionMatchEvidence::TYPE_URL,
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *     items: list<array{
     *         id: int,
     *         type: string,
     *         locator: string|null,
     *         createdAt: \DateTimeImmutable,
     *         submitterName: string,
     *         roundNumber: int,
     *         sequence: int,
     *         bracket: string,
     *         participantAName: string|null,
     *         participantBName: string|null
     *     }>,
     *     total: int,
     *     available: int,
     *     pages: int
     * }
     */
    public function page(Competition $competition, ?string $type, int $page): array
    {
        if ($page < 1 || $page > self::MAX_PAGE || ($type !== null && !in_array($type, self::TYPES, true))) {
            throw new \InvalidArgumentException('Invalid evidence board filters.');
        }

        $countBuilder = $this->entityManager->createQueryBuilder()
            ->select('COUNT(evidence.id)')
            ->from(CompetitionMatchEvidence::class, 'evidence')
            ->innerJoin('evidence.match', 'match')
            ->andWhere('match.competition = :competition')
            ->setParameter('competition', $competition);

        if ($type !== null) {
            $countBuilder->andWhere('evidence.type = :type')->setParameter('type', $type);
        }

        $total = (int) $countBuilder->getQuery()->getSingleScalarResult();
        $available = min($total, self::MAX_RESULTS);
        $pages = max(1, (int) ceil($available / self::PAGE_SIZE));
        if ($page > $pages) {
            throw new \InvalidArgumentException('Evidence board page is out of range.');
        }

        $builder = $this->entityManager->createQueryBuilder()
            ->select('evidence.id AS id')
            ->addSelect('evidence.type AS type')
            ->addSelect('evidence.locator AS locator')
            ->addSelect('evidence.createdAt AS createdAt')
            ->addSelect('submitter.displayName AS submitterName')
            ->addSelect('match.roundNumber AS roundNumber')
            ->addSelect('match.sequence AS sequence')
            ->addSelect('match.bracket AS bracket')
            ->addSelect('participantA.name AS participantAName')
            ->addSelect('participantB.name AS participantBName')
            ->from(CompetitionMatchEvidence::class, 'evidence')
            ->innerJoin('evidence.match', 'match')
            ->innerJoin('evidence.submittedBy', 'submitter')
            ->leftJoin('match.participantA', 'participantA')
            ->leftJoin('match.participantB', 'participantB')
            ->andWhere('match.competition = :competition')
            ->setParameter('competition', $competition)
            ->orderBy('evidence.createdAt', 'DESC')
            ->addOrderBy('evidence.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);

        if ($type !== null) {
            $builder->andWhere('evidence.type = :type')->setParameter('type', $type);
        }

        /** @var list<array{id: int|string, type: string, locator: string, createdAt: \DateTimeImmutable, submitterName: string, roundNumber: int|string, sequence: int|string, bracket: string, participantAName: string|null, participantBName: string|null}> $records */
        $records = $builder->getQuery()->getArrayResult();

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'id' => (int) $record['id'],
                'type' => $record['type'],
                'locator' => self::safeHttpUrl($record['locator']),
                'createdAt' => $record['createdAt'],
                'submitterName' => $record['submitterName'],
                'roundNumber' => (int) $record['roundNumber'],
                'sequence' => (int) $record['sequence'],
                'bracket' => $record['bracket'],
                'participantAName' => $record['participantAName'],
                'participantBName' => $record['participantBName'],
            ];
        }

        return ['items' => $items, 'total' => $total, 'available' => $available, 'pages' => $pages];
    }

    private static function safeHttpUrl(string $locator): ?string
    {
        if ($locator === '' || trim($locator) !== $locator || str_contains($locator, '\\') || preg_match('/[\x00-\x1F\x7F]/', $locator) === 1) {
            return null;
        }

        $parts = parse_url($locator);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($locator, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        return $locator;
    }
}
