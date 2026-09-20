<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildEventSignup> */
final class GuildEventSignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildEventSignup::class); }
    public function forEventAndMember(GuildEvent $event, GuildMember $member): ?GuildEventSignup { return $this->findOneBy(['event' => $event, 'member' => $member]); }
    public function confirmedCount(GuildEvent $event): int { return $this->count(['event' => $event, 'response' => GuildEventSignup::GOING]); }
    /** @return list<GuildEventSignup> */
    public function forEvent(GuildEvent $event): array { return $this->findBy(['event' => $event], ['response' => 'ASC', 'updatedAt' => 'ASC']); }
}
