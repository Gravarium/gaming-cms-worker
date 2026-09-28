<?php

declare(strict_types=1);

namespace App\Form\DownloadCatalogue;

use App\Entity\Download\DownloadPackage;
use Symfony\Component\Validator\Constraints as Assert;

final class DownloadPackageInput
{
    #[Assert\NotBlank(message: 'Enter a package title.', groups: ['create'])]
    #[Assert\Regex(pattern: '/\S/', message: 'Enter a package title.', groups: ['create'])]
    #[Assert\Length(max: 180, groups: ['create'])]
    public string $title = '';

    #[Assert\NotBlank(message: 'Enter a URL slug.', groups: ['create'])]
    #[Assert\Length(max: 200, groups: ['create'])]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
        message: 'Use lowercase letters, digits, and single hyphens in the slug.',
        groups: ['create'],
    )]
    public string $slug = '';

    #[Assert\Choice(choices: DownloadPackage::TYPES, groups: ['create'])]
    public string $type = 'file';

    #[Assert\Choice(choices: DownloadPackage::VISIBILITIES)]
    public string $visibility = 'public';

    #[Assert\Type('bool')]
    public bool $enabled = true;
}
