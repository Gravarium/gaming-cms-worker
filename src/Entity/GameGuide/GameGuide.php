<?php

declare(strict_types=1);

namespace App\Entity\GameGuide;

use App\Entity\Game;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'game_guide')]
#[ORM\Index(name: 'IDX_GAME_GUIDE_VERSION', columns: ['game_id', 'game_version', 'season'])]
class GameGuide
{
    public function __construct()
    {
        $this->validFrom = new \\DateTimeImmutable();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(name: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private ?Game $game = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewer_id', onDelete: 'SET NULL')]
    private ?User $reviewer = null;

    #[ORM\Column(length: 180)]
    private string $title = '';

    #[ORM\Column(length: 24, name: 'guide_type')]
    private string $guideType = '';

    #[ORM\Column(length: 80, name: 'game_version')]
    private string $gameVersion = '';

    #[ORM\Column(length: 80)]
    private string $season = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'valid_from')]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, name: 'valid_until')]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(length: 16, name: 'review_status')]
    private string $reviewStatus = 'draft';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, name: 'published_at')]
    private ?\DateTimeImmutable $publishedAt = null;

    public function getId(): ?int { return $this->id; }
}
