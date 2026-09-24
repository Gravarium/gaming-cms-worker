<?php

declare(strict_types=1);

namespace App\Entity\Download;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'download_mirror')]
class DownloadMirror
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DownloadVersion $version;

    #[ORM\Column(length: 500)]
    private string $url;

    #[ORM\Column(options: ['default' => false])]
    private bool $trusted = false;

    public function __construct(DownloadVersion $version, string $url, bool $trusted = false)
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || !isset($parts['host'])
        ) {
            throw new \InvalidArgumentException('Mirror must be credential-free HTTPS.');
        }

        $this->version = $version;
        $this->url = $url;
        $this->trusted = $trusted;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function isTrusted(): bool
    {
        return $this->trusted;
    }
}
