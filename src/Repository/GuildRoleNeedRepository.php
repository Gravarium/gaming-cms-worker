<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\Guild\GuildRoleNeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildRoleNeed> */
final class GuildRoleNeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuildRoleNeed::class);
    }

    /** @return list<array{id:int, roleKey:string, classKey:string, desiredCount:int, active:bool}> */
    public function findAdminRows(Guild $guild): array
    {
        $rows = $this->createQueryBuilder('need')
            ->select('need.id AS id', 'need.roleKey AS roleKey', 'need.classKey AS classKey', 'need.desiredCount AS desiredCount', 'need.active AS active')
            ->andWhere('need.guild = :guild')
            ->setParameter('guild', $guild)
            ->orderBy('need.roleKey', 'ASC')
            ->addOrderBy('need.classKey', 'ASC')
            ->getQuery()
            ->getArrayResult();
        /** @var list<array{id:mixed, roleKey:mixed, classKey:mixed, desiredCount:mixed, active:mixed}> $rows */

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'roleKey' => (string) $row['roleKey'],
            'classKey' => (string) $row['classKey'],
            'desiredCount' => (int) $row['desiredCount'],
            'active' => (bool) $row['active'],
        ], $rows);
    }

    /** @return array{id:int, roleKey:string, classKey:string, desiredCount:int, active:bool}|null */
    public function findAdminRow(int $id): ?array
    {
        $row = $this->createQueryBuilder('need')
            ->select('need.id AS id', 'need.roleKey AS roleKey', 'need.classKey AS classKey', 'need.desiredCount AS desiredCount', 'need.active AS active')
            ->andWhere('need.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
        /** @var array{id:mixed, roleKey:mixed, classKey:mixed, desiredCount:mixed, active:mixed}|null $row */

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'roleKey' => (string) $row['roleKey'],
            'classKey' => (string) $row['classKey'],
            'desiredCount' => (int) $row['desiredCount'],
            'active' => (bool) $row['active'],
        ];
    }

    public function identityExists(Guild $guild, Game $game, string $roleKey, string $classKey): bool
    {
        return (int) $this->createQueryBuilder('need')
            ->select('COUNT(need.id)')
            ->andWhere('need.guild = :guild')
            ->andWhere('need.game = :game')
            ->andWhere('need.roleKey = :roleKey')
            ->andWhere('need.classKey = :classKey')
            ->setParameter('guild', $guild)
            ->setParameter('game', $game)
            ->setParameter('roleKey', $roleKey)
            ->setParameter('classKey', $classKey)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<GuildRoleNeed> */
    public function findPublicActiveForGuild(Guild $guild): array
    {
        return $this->createQueryBuilder('need')
            ->addSelect('guild', 'game')
            ->join('need.guild', 'guild')
            ->join('need.game', 'game')
            ->andWhere('need.guild = :guild')
            ->andWhere('need.active = true')
            ->andWhere('need.desiredCount > 0')
            ->andWhere('guild.enabled = true')
            ->andWhere('guild.recruitmentOpen = true')
            ->andWhere('game.enabled = true')
            ->setParameter('guild', $guild)
            ->orderBy('need.roleKey', 'ASC')
            ->addOrderBy('need.classKey', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
