<?php

declare(strict_types=1);

namespace App\ContentTransfer;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class ContentTransferUpload
{
    #[Assert\NotNull]
    public ?UploadedFile $file = null;
}
