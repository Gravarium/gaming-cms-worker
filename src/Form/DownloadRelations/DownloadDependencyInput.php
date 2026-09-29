<?php

declare(strict_types=1);

namespace App\Form\DownloadRelations;

use App\Entity\Download\DownloadPackage;
use Symfony\Component\Validator\Constraints as Assert;

final class DownloadDependencyInput
{
    #[Assert\NotNull]
    public ?DownloadPackage $targetPackage = null;

    #[Assert\Choice(choices: ['requires', 'optional', 'conflicts'])]
    public string $kind = 'requires';

    #[Assert\Length(max: 80)]
    #[Assert\Regex(pattern: '/^[^\x00]*$/u')]
    public ?string $constraintExpression = null;
}
