<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildMember> */
final class GuildMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildMember::class); }
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
}
