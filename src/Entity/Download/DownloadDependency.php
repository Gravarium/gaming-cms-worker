<?php

declare(strict_types=1);

namespace App\Entity\Download;

use App\Repository\Download\DownloadDependencyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DownloadDependencyRepository::class)]
#[ORM\Table(name: 'download_dependency')]
class DownloadDependency
{
    public const KIND_REQUIRES = 'requires';
    public const KINDS = [self::KIND_REQUIRES, 'optional', 'conflicts'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DownloadVersion $version;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DownloadPackage $targetPackage;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $constraintExpression = null;

    public function __construct(
        DownloadVersion $version,
        DownloadPackage $targetPackage,
        string $kind,
        ?string $constraintExpression = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid dependency kind.');
        }
        if ($version->getPackage() === $targetPackage) {
            throw new \DomainException('Package cannot depend on itself.');
        }

        $constraintExpression = $constraintExpression === null ? null : trim($constraintExpression);
        if ($constraintExpression !== null && (!mb_check_encoding($constraintExpression, 'UTF-8') || str_contains($constraintExpression, "\0") || mb_strlen($constraintExpression, 'UTF-8') > 80)) {
            throw new \InvalidArgumentException('Dependency version expression is invalid or exceeds its storage column.');
        }
        $this->version = $version;
        $this->targetPackage = $targetPackage;
        $this->kind = $kind;
        $this->constraintExpression = $constraintExpression === '' ? null : $constraintExpression;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): DownloadVersion
    {
        return $this->version;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTargetPackage(): DownloadPackage
    {
        return $this->targetPackage;
    }

    public function getConstraintExpression(): ?string
    {
        return $this->constraintExpression;
    }
}
