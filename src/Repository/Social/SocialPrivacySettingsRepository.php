<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialPrivacySettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialPrivacySettings> */
final class SocialPrivacySettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPrivacySettings::class);
    }

    public function forUser(User $user): ?SocialPrivacySettings
    {
        return $this->find($user->getId());
    }
}
