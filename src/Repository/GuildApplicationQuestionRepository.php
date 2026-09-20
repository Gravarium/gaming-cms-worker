<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildApplicationQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildApplicationQuestion> */
final class GuildApplicationQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildApplicationQuestion::class); }

    /** @return list<GuildApplicationQuestion> */
    public function enabledForGuild(Guild $guild): array
    {
        return $this->findBy(['guild' => $guild, 'enabled' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
