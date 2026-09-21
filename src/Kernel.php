<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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

    protected function build(ContainerBuilder $container): void
    {
        if ($this->environment === 'prod') {
            require_once dirname(__DIR__).'/private/ProductionExternalConnectorPrivateConfiguration.php';
            require_once dirname(__DIR__).'/private/ProductionS3MediaTargetConfigurationProvider.php';
        }

        parent::build($container);
    }
}
