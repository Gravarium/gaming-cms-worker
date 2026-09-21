<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MenuItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MenuItemRepository::class)]
#[ORM\Table(name: 'menu_item')]
class MenuItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $label = '';

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    private ?string $url = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ContentEntry $page = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $openNewWindow = false;

    public function getId(): ?int { return $this->id; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = trim($label); return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): self
    {
        $url = $url === null ? null : trim($url);
        if ($url === '') {
            $url = null;
        }
        if ($url !== null) {
            $parts = parse_url($url);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';
            if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException('External menu URLs must use HTTP(S) without embedded credentials.');
            }
        }
        $this->url = $url;

        return $this;
    }
    public function getPage(): ?ContentEntry { return $this->page; }
    public function setPage(?ContentEntry $page): self { $this->page = $page; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function isOpenNewWindow(): bool { return $this->openNewWindow; }
    public function setOpenNewWindow(bool $openNewWindow): self { $this->openNewWindow = $openNewWindow; return $this; }
}
