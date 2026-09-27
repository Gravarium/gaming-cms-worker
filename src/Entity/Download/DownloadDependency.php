<?php

declare(strict_types=1);

namespace App\Entity\Download;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'download_dependency')]
class DownloadDependency
{
    public const KINDS = ['requires', 'optional', 'conflicts'];

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
        $this->version = $version;
        $this->targetPackage = $targetPackage;
        $this->kind = $kind;
        $this->constraintExpression = $constraintExpression === '' ? null : $constraintExpression;
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
