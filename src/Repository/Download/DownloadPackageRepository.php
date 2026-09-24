<?php
declare(strict_types=1);
namespace App\Repository\Download;
use App\Entity\Download\DownloadPackage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<DownloadPackage> */
final class DownloadPackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry){parent::__construct($registry,DownloadPackage::class);}
    /** @return list<DownloadPackage> */ public function enabledPackages():array{return $this->findBy(['enabled'=>true],['title'=>'ASC']);}
    public function enabledBySlug(string $slug):?DownloadPackage{return $this->findOneBy(['slug'=>$slug,'enabled'=>true]);}
}
