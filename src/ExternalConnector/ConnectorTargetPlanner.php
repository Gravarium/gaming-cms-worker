<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\Repository\ExternalConnectorTargetRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConnectorTargetPlanner
{
    public function __construct(
        private ConnectorProviderCatalog $catalog,
        private ExternalConnectorTargetRepository $targets,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param list<string> $choices
     *  @return array{created: int, skipped: int, keys: list<string>}
     */
    public function plan(array $choices): array
    {
        $catalog = $this->catalog->indexed();
        $selected = [];
        foreach ($choices as $choice) {
            $choice = strtolower(trim($choice));
            if (isset($catalog[$choice])) {
                $selected[$choice] = $catalog[$choice];
            }
        }

        $created = 0;
        $skipped = 0;
        $keys = [];
        foreach ($selected as $provider) {
            $targetKey = $provider['capability'].'-'.$provider['key'];
            if ($this->targets->findOneBy(['capability' => $provider['capability'], 'targetKey' => $targetKey]) !== null) {
                ++$skipped;
                continue;
            }

            $target = (new ExternalConnectorTarget())
                ->setCapability($provider['capability'])
                ->setTargetKey($targetKey)
                ->setProviderKey($provider['key'])
                ->setDisplayName($provider['name'])
                ->setPriority(100 + ($created * 10))
                ->setRequired(false)
                ->setEnabled(false)
                ->setConfigurationReference($provider['capability'].'.'.$provider['key']);
            $this->entityManager->persist($target);
            $keys[] = $targetKey;
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return ['created' => $created, 'skipped' => $skipped, 'keys' => $keys];
    }
}
