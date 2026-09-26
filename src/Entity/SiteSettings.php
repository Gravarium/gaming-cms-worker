<?php

declare(strict_types=1);

namespace App\Entity;

use App\Internationalization\LocalePolicy;
use App\Repository\SiteSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: SiteSettingsRepository::class)]
#[ORM\Table(name: 'site_settings')]
class SiteSettings
{
    private const MAX_HOME_TITLE_BYTES = 720;
    private const MAX_HOME_TITLE_LENGTH = 180;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $siteName = 'Gaming CMS';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $description = 'Modulare Plattform für Gaming-Communities.';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $homeTitle = 'Deine Community. Deine Inhalte.';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $homeText = 'News, Seiten und Community-Funktionen an einem Ort.';

    #[ORM\Column(length: 7)]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Bitte eine Farbe wie #7c5cff eingeben.')]
    private string $primaryColor = '#7c5cff';

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: ['dark', 'light', 'system'])]
    private string $colorScheme = 'dark';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $faviconPath = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $gamingEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $videoEnabled = true;

    #[ORM\Column(length: 40, options: ['default' => 'nebula'])]
    private string $themeKey = 'nebula';

    #[ORM\Column(length: 10, options: ['default' => 'de'])]
    private string $defaultLocale = 'de';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '["de"]'])]
    private array $enabledLocales = ['de'];

    public function getId(): ?int { return $this->id; }
    public function getSiteName(): string { return $this->siteName; }
    public function setSiteName(string $siteName): self { $this->siteName = trim($siteName); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description === null ? null : trim($description); return $this; }
    public function getHomeTitle(): string { return $this->homeTitle; }
    public function setHomeTitle(string $homeTitle): self
    {
        $this->assertHomeTitleColumnBoundary($homeTitle);

        $this->homeTitle = trim($homeTitle);

        return $this;
    }
    public function getHomeText(): string { return $this->homeText; }
    public function setHomeText(string $homeText): self { $this->homeText = trim($homeText); return $this; }
    public function getPrimaryColor(): string { return $this->primaryColor; }
    public function setPrimaryColor(string $primaryColor): self { $this->primaryColor = strtolower($primaryColor); return $this; }
    public function getColorScheme(): string { return $this->colorScheme; }
    public function setColorScheme(string $colorScheme): self { $this->colorScheme = $colorScheme; return $this; }
    public function getLogoPath(): ?string { return $this->logoPath; }
    public function setLogoPath(?string $logoPath): self { $this->logoPath = $logoPath; return $this; }
    public function getFaviconPath(): ?string { return $this->faviconPath; }
    public function setFaviconPath(?string $faviconPath): self { $this->faviconPath = $faviconPath; return $this; }
    public function isGamingEnabled(): bool { return $this->gamingEnabled; }
    public function setGamingEnabled(bool $gamingEnabled): self { $this->gamingEnabled = $gamingEnabled; return $this; }
    public function isVideoEnabled(): bool { return $this->videoEnabled; }
    public function setVideoEnabled(bool $videoEnabled): self { $this->videoEnabled = $videoEnabled; return $this; }
    public function getThemeKey(): string { return $this->themeKey; }
    public function setThemeKey(string $themeKey): self { $this->themeKey = $themeKey; return $this; }
    public function getDefaultLocale(): string { return $this->defaultLocale; }
    public function setDefaultLocale(string $defaultLocale): self { $this->defaultLocale = $defaultLocale; return $this; }
    /** @return list<string> */
    public function getEnabledLocales(): array { return $this->enabledLocales; }
    /** @param list<string> $enabledLocales */
    public function setEnabledLocales(array $enabledLocales): self
    {
        $this->enabledLocales = array_values(array_unique(array_filter(
            $enabledLocales,
            static fn (string $locale): bool => isset(LocalePolicy::SUPPORTED[$locale]),
        )));
        return $this;
    }
    /** @return array<string, string> */
    public function getEnabledLocaleLabels(): array
    {
        return array_intersect_key(LocalePolicy::SUPPORTED, array_flip($this->enabledLocales));
    }
    #[Assert\Callback]
    public function validateLocales(ExecutionContextInterface $context): void
    {
        if ($this->enabledLocales === []) {
            $context->buildViolation('Mindestens eine Sprache muss aktiv sein.')->atPath('enabledLocales')->addViolation();
        }
        if (!isset(LocalePolicy::SUPPORTED[$this->defaultLocale])) {
            $context->buildViolation('Die Standardsprache wird nicht unterstützt.')->atPath('defaultLocale')->addViolation();
        } elseif (!in_array($this->defaultLocale, $this->enabledLocales, true)) {
            $context->buildViolation('Die Standardsprache muss auch aktiviert sein.')->atPath('defaultLocale')->addViolation();
        }
    }
}

