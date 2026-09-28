<?php

declare(strict_types=1);

namespace App\Form\DownloadCatalogue;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class DownloadVersionInput
{
    #[Assert\NotBlank(message: 'Enter a version number.')]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9._+-]{1,80}$/D',
        message: 'Use letters, numbers, dots, plus signs, hyphens and underscores.',
    )]
    public string $version = '';

    #[Assert\NotNull(message: 'Choose a download file.')]
    public ?UploadedFile $file = null;

    #[Assert\Length(max: 2000, maxMessage: 'Compatibility notes may not exceed {{ limit }} characters.')]
    public string $compatibility = '';

    #[Assert\Length(max: 12000, maxMessage: 'Release notes may not exceed {{ limit }} characters.')]
    public string $changelog = '';

    /** @return list<string> */
    public function compatibilityValues(): array
    {
        $lines = preg_split('/\R/u', $this->compatibility) ?: [];
        $values = array_map(
            static fn (string $line): string => trim($line),
            $lines,
        );

        return array_values(array_unique(array_filter(
            $values,
            static fn (string $value): bool => $value !== '',
        )));
    }
}
