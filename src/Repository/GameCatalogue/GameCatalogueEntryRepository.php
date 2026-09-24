<?php
declare(strict_types=1);
namespace App\Repository\GameCatalogue;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<GameCatalogueEntry> */
final class GameCatalogueEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry){parent::__construct($registry,GameCatalogueEntry::class);}
    /** @return list<GameCatalogueEntry> */
    public function publicEntries():array{return $this->createQueryBuilder('entry')->join('entry.game','game')->andWhere('entry.enabled = true')->andWhere('game.enabled = true')->orderBy('game.name','ASC')->getQuery()->getResult();}
    public function publicBySlug(string $slug):?GameCatalogueEntry{return $this->createQueryBuilder('entry')->join('entry.game','game')->andWhere('entry.enabled = true')->andWhere('game.enabled = true')->andWhere('game.slug = :slug')->setParameter('slug',$slug)->getQuery()->getOneOrNullResult();}
}
