<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentReleaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContentReleaseRepository::class)]
#[ORM\Table(name: 'content_release')]
class ContentRelease
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CANCELLED = 'cancelled';
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $name = '';
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $description = null;
    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_PUBLISHED, self::STATUS_CANCELLED])]
    private string $status = self::STATUS_DRAFT;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;
    /** @var Collection<int, ContentEntry> */
    #[ORM\ManyToMany(targetEntity: ContentEntry::class)]
    #[ORM\JoinTable(name: 'content_release_entry')]
    private Collection $entries;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->entries = new ArrayCollection(); $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self
    {
        $this->assertUnpublished();
        $this->name = trim($name);

        return $this;
    }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self
    {
        $this->assertUnpublished();
        $description = $description === null ? null : trim($description);
        $this->description = $description === '' ? null : $description;

        return $this;
    }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        $this->assertUnpublished();
        if ($status === self::STATUS_PUBLISHED) {
            throw new \DomainException('Ein Release muss über den Veröffentlichungsablauf veröffentlicht werden.');
        }
        $this->status = $status;

        return $this;
    }
    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeImmutable $at): self
    {
        $this->assertUnpublished();
        $this->scheduledAt = $at;

        return $this;
    }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $user): self
    {
        $this->assertUnpublished();
        $this->createdBy = $user;

        return $this;
    }
    /** @return Collection<int, ContentEntry> */
    public function getEntries(): Collection
    {
        return $this->status === self::STATUS_PUBLISHED
            ? new ArrayCollection($this->entries->toArray())
            : $this->entries;
    }

    public function addEntry(ContentEntry $entry): self
    {
        $this->assertUnpublished();
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
        }

        return $this;
    }

    public function removeEntry(ContentEntry $entry): self
    {
        $this->assertUnpublished();
        $this->entries->removeElement($entry);

        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function synchronizeSchedule(): void
    {
        if ($this->status === self::STATUS_SCHEDULED) {
            if ($this->scheduledAt === null || $this->scheduledAt <= new \DateTimeImmutable()) { throw new \DomainException('Ein geplanter Release benötigt einen zukünftigen Zeitpunkt.'); }
            if ($this->publishableEntries() === []) { throw new \DomainException('Ein geplanter Release benötigt mindestens einen nicht gelöschten Inhalt.'); }
            return;
        }
        if ($this->status === self::STATUS_DRAFT || $this->status === self::STATUS_CANCELLED) { $this->scheduledAt = null; }
    }
    public function isDue(\DateTimeImmutable $now): bool { return $this->status === self::STATUS_SCHEDULED && $this->scheduledAt !== null && $this->scheduledAt <= $now; }
    public function publish(\DateTimeImmutable $now): int
    {
        if ($this->status === self::STATUS_CANCELLED || $this->status === self::STATUS_PUBLISHED) { return 0; }
        $entries = $this->publishableEntries();
        if ($entries === []) { throw new \DomainException('Ein Release ohne veröffentlichbare Inhalte kann nicht veröffentlicht werden.'); }
        $count = 0;
        foreach ($entries as $entry) {
            $entry->setStatus(ContentEntry::STATUS_PUBLISHED)->setScheduledAt(null)->setScheduledUnpublishAt(null)->setPublishedAt($now);
            ++$count;
        }
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = $now;
        $this->scheduledAt = null;
        return $count;
    }

    private function assertUnpublished(): void
    {
        if ($this->status === self::STATUS_PUBLISHED) {
            throw new \DomainException('Veröffentlichte Releases sind unveränderlich.');
        }
    }

    /** @return list<ContentEntry> */
    private function publishableEntries(): array
    {
        return array_values(array_filter(
            $this->entries->toArray(),
            static fn (ContentEntry $entry): bool => $entry->getStatus() !== ContentEntry::STATUS_TRASHED,
        ));
    }
}
