<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialAttachment;
use App\Entity\Social\SocialMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialAttachment> */
final class SocialAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialAttachment::class);
    }

    /** @return list<SocialAttachment> */
    public function forMessage(SocialMessage $message): array
    {
        return $this->findBy(['message' => $message], ['createdAt' => 'ASC']);
    }
}
