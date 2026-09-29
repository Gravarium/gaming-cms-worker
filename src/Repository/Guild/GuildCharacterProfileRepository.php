<?php

declare(strict_types=1);

namespace App\Repository\Guild;

use App\Entity\Guild;
use App\Entity\Guild\GuildCharacterProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildCharacterProfile> */
final class GuildCharacterProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry){parent::__construct($registry,GuildCharacterProfile::class);}

    /** @return list<GuildCharacterProfile> */
    public function forGuild(Guild $guild,?int $gameId=null,?string $role=null,?string $characterClass=null):array
    {
        $qb=$this->createQueryBuilder('character')->andWhere('character.guild = :guild')->setParameter('guild',$guild)->andWhere('character.active = true');
        if($gameId!==null)$qb->join('character.game','game')->andWhere('game.id = :gameId')->setParameter('gameId',$gameId);
        if($role!==null)$qb->andWhere('character.role = :role')->setParameter('role',$role);
        if($characterClass!==null)$qb->andWhere('character.characterClass = :class')->setParameter('class',$characterClass);
        return $qb->orderBy('character.mainCharacter','DESC')->addOrderBy('character.characterName','ASC')->getQuery()->getResult();
    }
}
