<?php

declare(strict_types=1);

namespace App\Entity\GameGuide;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'game_guide_tier_entry')]
#[ORM\UniqueConstraint(name: 'UNIQ_GAME_GUIDE_TIER_ENTRY', columns: ['guide_id', 'entry_key'])]
class GameGuideTierEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameGuide::class)]
    #[ORM\JoinColumn(name: 'guide_id', nullable: false, onDelete: 'CASCADE')]
    private ?GameGuide $guide = null;

    #[ORM\Column(length: 120, name: 'entry_key')]
    private string $entryKey = '';

    #[ORM\Column(length: 1)]
    private string $tier = 'F';

    #[ORM\Column(type: Types::TEXT)]
    private string $reason = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $criteria = '';

    #[ORM\Column(length: 1000)]
    private string $provenance = '';

    public function getId(): ?int { return $this->id; }
}
