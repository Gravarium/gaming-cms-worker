<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuildMember;
use Symfony\Component\Validator\Constraints as Assert;

final class GuildOnboardingTaskInput
{
    #[Assert\NotBlank(message: 'Gib eine Bezeichnung für die Aufgabe ein.')]
    #[Assert\Length(max: 180, maxMessage: 'Die Bezeichnung darf höchstens 180 Zeichen lang sein.')]
    private string $label = '';

    #[Assert\NotNull(message: 'Wähle ein aktives Mitglied aus.')]
    private ?GuildMember $member = null;

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getMember(): ?GuildMember
    {
        return $this->member;
    }

    public function setMember(?GuildMember $member): self
    {
        $this->member = $member;

        return $this;
    }
}
