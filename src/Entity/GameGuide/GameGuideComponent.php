<?php

declare(strict_types=1);

namespace App\Entity\GameGuide;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'game_guide_component')]
#[ORM\UniqueConstraint(name: 'UNIQ_GAME_GUIDE_COMPONENT_POSITION', columns: ['guide_id', 'position'])]
class GameGuideComponent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameGuide::class)]
    #[ORM\JoinColumn(name: 'guide_id', nullable: false, onDelete: 'CASCADE')]
    private ?GameGuide $guide = null;

    #[ORM\Column(length: 24, name: 'component_type')]
    private string $componentType = '';

    #[ORM\Column(length: 120, name: 'component_key')]
    private string $componentKey = '';

    #[ORM\Column]
    private int $position = 1;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $alternatives = [];

    public function getId(): ?int { return $this->id; }
}
