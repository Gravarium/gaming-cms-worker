<?php
declare(strict_types=1);
namespace App\Repository;
use App\Entity\CredentialRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\CredentialRecord as BaseCredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;
/** @extends ServiceEntityRepository<CredentialRecord> */
final class CredentialRecordRepository extends ServiceEntityRepository implements CredentialRecordRepositoryInterface, CanSaveCredentialRecord
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, CredentialRecord::class); }
    public function findOneByCredentialId(string $publicKeyCredentialId): ?BaseCredentialRecord { return $this->findOneBy(['publicKeyCredentialId' => $publicKeyCredentialId]); }
    /** @return list<CredentialRecord> */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $userEntity): array { return $this->findBy(['userHandle' => $userEntity->id], ['createdAt' => 'DESC']); }
    public function saveCredentialRecord(BaseCredentialRecord $credential): void
    {
        $stored = $this->findOneByCredentialId($credential->publicKeyCredentialId);
        if ($stored instanceof CredentialRecord) { $stored->applyAuthenticationResult($credential); $credential = $stored; }
        elseif (!$credential instanceof CredentialRecord) { $credential = CredentialRecord::fromCredentialRecord($credential); }
        $this->getEntityManager()->persist($credential); $this->getEntityManager()->flush();
    }
    public function remove(CredentialRecord $credential): void { $this->getEntityManager()->remove($credential); $this->getEntityManager()->flush(); }
}
