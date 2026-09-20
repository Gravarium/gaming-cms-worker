<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    public function __construct(string $environment, bool $debug)
    {
        if (!in_array($environment, ['dev', 'test'], true)) {
            throw new \LogicException('Sanitized worker snapshots are development/test-only and cannot boot as production.');
        }

        parent::__construct($environment, $debug);
    }
    use MicroKernelTrait;
}
