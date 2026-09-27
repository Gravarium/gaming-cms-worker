<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildMember> */
final class GuildMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildMember::class); }

    public function countForAdminGuild(Guild $guild, string $query, ?bool $active): int
    {
        $builder = $this->createQueryBuilder('guildMember')
            ->select('COUNT(guildMember.id)')
            ->andWhere('guildMember.guild = :guild')
            ->setParameter('guild', $guild);
        $this->applyAdminFilters($builder, $query, $active);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<GuildMember>
     */
    public function findForAdminGuild(
        Guild $guild,
        string $query,
        ?bool $active,
        int $limit,
        int $offset,
    ): array {
        $builder = $this->createQueryBuilder('guildMember')
            ->andWhere('guildMember.guild = :guild')
            ->setParameter('guild', $guild)
            ->orderBy('guildMember.leader', 'DESC')
            ->addOrderBy('guildMember.position', 'ASC')
            ->addOrderBy('guildMember.characterName', 'ASC')
            ->addOrderBy('guildMember.id', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)));
        $this->applyAdminFilters($builder, $query, $active);

        return $builder->getQuery()->getResult();
    }

    /** @return list<GuildMember> */
    public function activeForGuild(Guild $guild): array { return $this->findBy(['guild' => $guild, 'active' => true], ['leader' => 'DESC', 'position' => 'ASC', 'characterName' => 'ASC']); }
    /** @return list<GuildMember> */
    public function forUser(User $user): array { return $this->findBy(['user' => $user, 'active' => true], ['characterName' => 'ASC']); }
    /** @return list<GuildMember> */
    public function forUserAndGuild(User $user, Guild $guild): array { return $this->findBy(['user' => $user, 'guild' => $guild, 'active' => true], ['characterName' => 'ASC']); }
    /** @return list<User> */
    public function usersForGuild(Guild $guild): array
    {
        return $this->createQueryBuilder('member')->select('DISTINCT account')->join('member.user', 'account')->andWhere('member.guild = :guild')->andWhere('member.active = true')->setParameter('guild', $guild)->getQuery()->getResult();
    }

    private function applyAdminFilters(QueryBuilder $builder, string $query, ?bool $active): void
    {
        $query = mb_strtolower(trim($query));
        if ($query !== '') {
            $builder
                ->andWhere('LOWER(guildMember.characterName) LIKE :search')
                ->setParameter('search', '%'.$query.'%');
        }

        if ($active !== null) {
            $builder
                ->andWhere('guildMember.active = :active')
                ->setParameter('active', $active);
        }
    }
}
