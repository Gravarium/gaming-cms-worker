<?php
declare(strict_types=1);
namespace App\Repository\GameCatalogue;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<GameRelease> */
final class GameReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry){parent::__construct($registry,GameRelease::class);}
    /** @return list<GameRelease> */
    public function upcoming(\DateTimeImmutable $from,\DateTimeImmutable $to):array{return $this->createQueryBuilder('release')->join('release.entry','entry')->join('entry.game','game')->andWhere('entry.enabled = true')->andWhere('game.enabled = true')->andWhere('release.releaseAt >= :from')->andWhere('release.releaseAt <= :to')->andWhere('release.status != :cancelled')->setParameter('from',$from)->setParameter('to',$to)->setParameter('cancelled','cancelled')->orderBy('release.releaseAt','ASC')->getQuery()->getResult();}
    /** @return list<GameRelease> */ public function forEntry(GameCatalogueEntry $entry):array{return $this->findBy(['entry'=>$entry],['releaseAt'=>'ASC']);}
}
