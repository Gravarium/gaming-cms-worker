<?php

declare(strict_types=1);

namespace App\Entity\Competition;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'competition_match_evidence')]
class CompetitionMatchEvidence
{
    public const TYPE_SCREENSHOT = 'screenshot';
    public const TYPE_VIDEO = 'video';
    public const TYPE_URL = 'url';
    public const VISIBILITY_MATCH_PARTICIPANTS = 'participants';
    public const VISIBILITY_PUBLIC = 'public';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CompetitionMatch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CompetitionMatch $match = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $submittedBy = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TYPE_SCREENSHOT, self::TYPE_VIDEO, self::TYPE_URL])]
    private string $type = self::TYPE_URL;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $locator = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::VISIBILITY_MATCH_PARTICIPANTS, self::VISIBILITY_PUBLIC])]
    private string $visibility = self::VISIBILITY_MATCH_PARTICIPANTS;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMatch(): ?CompetitionMatch { return $this->match; }
    public function setMatch(CompetitionMatch $match): self
    {
        if ($this->submittedBy !== null && !$match->hasUser($this->submittedBy)) { throw new \DomainException('Evidence must be submitted by a match participant.'); }
        $this->match = $match;
        return $this;
    }
    public function getSubmittedBy(): ?User { return $this->submittedBy; }
    public function setSubmittedBy(User $submittedBy): self
    {
        if ($this->match !== null && !$this->match->hasUser($submittedBy)) { throw new \DomainException('Evidence must be submitted by a match participant.'); }
        $this->submittedBy = $submittedBy;
        return $this;
    }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self
    {
        if (!in_array($type, [self::TYPE_SCREENSHOT, self::TYPE_VIDEO, self::TYPE_URL], true)) { throw new \InvalidArgumentException('Unsupported evidence type.'); }
        $this->type = $type;
        return $this;
    }
    public function getLocator(): string { return $this->locator; }
    public function setLocator(string $locator): self
    {
        $locator = trim($locator);
        $scheme = strtolower((string) parse_url($locator, PHP_URL_SCHEME));
        if (!filter_var($locator, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) { throw new \InvalidArgumentException('Evidence locator must be an HTTP(S) URL.'); }
        $this->locator = $locator;
        return $this;
    }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $description = $description === null ? null : trim($description); $this->description = $description === '' ? null : $description; return $this; }
    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $visibility): self
    {
        if (!in_array($visibility, [self::VISIBILITY_MATCH_PARTICIPANTS, self::VISIBILITY_PUBLIC], true)) { throw new \InvalidArgumentException('Unsupported evidence visibility.'); }
        $this->visibility = $visibility;
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
