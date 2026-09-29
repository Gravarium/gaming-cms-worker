<?php

declare(strict_types=1);

namespace App\Form\DownloadRelations;

use Symfony\Component\Validator\Constraints as Assert;

final class DownloadMirrorInput
{
    #[Assert\NotBlank]
    #[Assert\Url(protocols: ['https'], requireTld: true)]
    #[Assert\Length(max: 500)]
    public string $url = '';

    public bool $trusted = false;
}
