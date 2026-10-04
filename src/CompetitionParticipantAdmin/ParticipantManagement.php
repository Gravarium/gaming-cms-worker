<?php

declare(strict_types=1);

namespace App\CompetitionParticipantAdmin;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class ParticipantManagement
{
    public const LIMIT = 200;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array{participants: list<CompetitionParticipant>, total: int, truncated: bool, mutable: bool} */
    public function board(Competition $competition): array
    {
        $repository = $this->entityManager->getRepository(CompetitionParticipant::class);
        /** @var list<CompetitionParticipant> $participants */
        $participants = $repository->createQueryBuilder('participant')
            ->andWhere('participant.competition = :competition')
            ->setParameter('competition', $competition)
            ->addOrderBy('participant.seed', 'ASC')
            ->addOrderBy('participant.registeredAt', 'ASC')
            ->addOrderBy('participant.id', 'ASC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();
        $total = $repository->count(['competition' => $competition]);

        return [
            'participants' => $participants,
            'total' => $total,
            'truncated' => $total > self::LIMIT,
            'mutable' => $this->isMutable($competition),
        ];
    }

    public function assignSeed(int $competitionId, int $participantId, int $seed): void
    {
        if ($seed < 1) {
            throw new \InvalidArgumentException('Die Setzposition muss eine positive ganze Zahl sein.');
        }

        $this->mutate($competitionId, $participantId, function (CompetitionParticipant $participant, Competition $competition) use ($seed): void {
            if (!$participant->isActive()) {
                throw new \DomainException('Nur aktive Anmeldungen können gesetzt werden.');
            }

            $duplicate = $this->entityManager->getRepository(CompetitionParticipant::class)->createQueryBuilder('other')
                ->select('other.id')
                ->andWhere('other.competition = :competition')
                ->andWhere('other.seed = :seed')
                ->andWhere('other.id != :participant')
                ->setParameter('competition', $competition)
                ->setParameter('seed', $seed)
                ->setParameter('participant', $participant->getId())
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($duplicate !== null) {
                throw new \DomainException('Diese Setzposition ist bereits vergeben.');
            }

            $participant->setSeed($seed);
        });
    }

    public function withdraw(int $competitionId, int $participantId, ?string $reason): void
    {
        $reason = $this->reason($reason, false);
        $this->mutate($competitionId, $participantId, static function (CompetitionParticipant $participant) use ($reason): void {
            if (!$participant->isActive()) {
                throw new \DomainException('Diese Anmeldung ist nicht mehr aktiv.');
            }
            $participant->withdraw($reason);
        });
    }

    public function disqualify(int $competitionId, int $participantId, ?string $reason): void
    {
        $reason = $this->reason($reason, true);
        $this->mutate($competitionId, $participantId, static function (CompetitionParticipant $participant) use ($reason): void {
            if (!$participant->isActive()) {
                throw new \DomainException('Diese Anmeldung ist nicht mehr aktiv.');
            }
            $participant->disqualify($reason ?? '');
        });
    }

    /** @param callable(CompetitionParticipant, Competition): void $operation */
    private function mutate(int $competitionId, int $participantId, callable $operation): void
    {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($competitionId, $participantId, $operation): void {
            $competition = $entityManager->find(Competition::class, $competitionId);
            $participant = $entityManager->find(CompetitionParticipant::class, $participantId);
            if (!$competition instanceof Competition || !$participant instanceof CompetitionParticipant) {
                throw new \DomainException('Competition oder Anmeldung wurde nicht gefunden.');
            }

            $entityManager->lock($competition, LockMode::PESSIMISTIC_WRITE);
            $entityManager->lock($participant, LockMode::PESSIMISTIC_WRITE);
            if ($participant->getCompetition()?->getId() !== $competition->getId()) {
                throw new \DomainException('Die Anmeldung gehört nicht zu dieser Competition.');
            }
            if (!$this->isMutable($competition)) {
                throw new \DomainException('Teilnehmer können nach der Paarungserstellung nicht mehr geändert werden.');
            }

            $operation($participant, $competition);
            $entityManager->flush();
        });
    }

    private function isMutable(Competition $competition): bool
    {
        if (!in_array($competition->getStatus(), [Competition::STATUS_DRAFT, Competition::STATUS_OPEN], true)) {
            return false;
        }

        return $this->entityManager->getRepository(CompetitionMatch::class)->createQueryBuilder('match')
            ->select('match.id')
            ->andWhere('match.competition = :competition')
            ->setParameter('competition', $competition)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() === null;
    }

    private function reason(?string $reason, bool $required): ?string
    {
        $reason = trim((string) $reason);
        if ($required && $reason === '') {
            throw new \InvalidArgumentException('Eine Disqualifikation benötigt eine Begründung.');
        }
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new \InvalidArgumentException('Die Begründung darf höchstens 500 Zeichen lang sein.');
        }

        return $reason === '' ? null : $reason;
    }
}
