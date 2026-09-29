<?php

declare(strict_types=1);

namespace App\Entity\Download;

use App\Repository\Download\DownloadMirrorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DownloadMirrorRepository::class)]
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
        $url = trim($url);
        $parts = parse_url($url);
        if (
            $url === ''
            || !mb_check_encoding($url, 'UTF-8')
            || str_contains($url, "\0")
            || mb_strlen($url, 'UTF-8') > 500
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || !isset($parts['host'])
            || $parts['host'] === ''
        ) {
            throw new \InvalidArgumentException('Mirror must be a valid credential-free HTTPS URL within the storage limit.');
        }

        $this->version = $version;
        $this->url = $url;
        $this->trusted = $trusted;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): DownloadVersion
    {
        return $this->version;
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
