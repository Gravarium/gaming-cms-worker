<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccessRoleRepository;
use App\Security\CmsPermission;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AccessRoleRepository::class)]
#[ORM\Table(name: 'access_role')]
#[ORM\UniqueConstraint(name: 'uniq_access_role_key', columns: ['role_key'])]
class AccessRole
{
    private const MAX_KEY_BYTES = 320;
    private const MAX_KEY_LENGTH = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'role_key', length: 80)]
    #[Assert\Regex(pattern: '/^[a-z][a-z0-9_-]{2,79}$/')]
    private string $key = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $systemRole = false;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'accessRoles')]
    private Collection $users;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getKey(): string { return $this->key; }
    public function setKey(string $key): self
    {
        if (!mb_check_encoding($key, 'UTF-8') || str_contains($key, "\0")) {
            throw new \InvalidArgumentException('Access role key is invalid or exceeds the allowed length.');
        }

        $normalizedKey = mb_strtolower(trim($key));
        $this->assertKeyColumnBoundary($normalizedKey);
        $this->key = $normalizedKey;

        return $this;
    }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $description = $description === null ? null : trim($description); $this->description = $description === '' ? null : $description; return $this; }
    /** @return list<string> */
    public function getPermissions(): array { return $this->permissions; }
    /** @param list<string> $permissions */
    public function setPermissions(array $permissions): self
    {
        foreach ($permissions as $permission) {
            if (!in_array($permission, CmsPermission::ALL, true)) { throw new \InvalidArgumentException('Unknown CMS permission.'); }
        }
        $this->permissions = array_values(array_unique($permissions));
        return $this;
    }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function isSystemRole(): bool { return $this->systemRole; }
    public function setSystemRole(bool $systemRole): self { $this->systemRole = $systemRole; return $this; }
    /** @return Collection<int, User> */
    public function getUsers(): Collection { return $this->users; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    private function assertKeyColumnBoundary(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_BYTES || mb_strlen($key, 'UTF-8') > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException('Access role key is invalid or exceeds the allowed length.');
        }
    }

}
