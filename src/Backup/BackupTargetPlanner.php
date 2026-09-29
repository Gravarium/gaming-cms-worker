<?php

declare(strict_types=1);

namespace App\Backup;

use App\Entity\ExternalConnectorTarget;
use App\Repository\ExternalConnectorTargetRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BackupTargetPlanner
{
    public function __construct(
        private BackupProviderCatalog $catalog,
        private ExternalConnectorTargetRepository $targets,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<array-key, mixed> $providerKeys
     *  @return array{created: int, skipped: int, keys: list<string>, rejected: bool}
     */
    public function plan(array $providerKeys): array
    {
        $catalog = $this->catalog->indexed();
        if (count($providerKeys) > count($catalog)) {
            return ['created' => 0, 'skipped' => 0, 'keys' => [], 'rejected' => true];
        }

        $selected = [];
        foreach ($providerKeys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $key = strtolower(trim($key));
            if (isset($catalog[$key])) {
                $selected[$key] = $catalog[$key];
            }
        }

        $created = 0;
        $skipped = 0;
        $keys = [];
        $priority = 100;
        foreach ($selected as $key => $provider) {
            $targetKey = 'backup-'.$key;
            if ($this->targets->findOneBy([
                'capability' => ExternalConnectorTarget::CAPABILITY_BACKUP,
                'targetKey' => $targetKey,
            ]) !== null) {
                ++$skipped;
                continue;
            }

            $target = (new ExternalConnectorTarget())
                ->setCapability(ExternalConnectorTarget::CAPABILITY_BACKUP)
                ->setTargetKey($targetKey)
                ->setProviderKey($key)
                ->setDisplayName($provider['name'])
                ->setPriority($priority)
                ->setRequired(false)
                ->setEnabled(false)
                ->setConfigurationReference('backup.'.$key);
            $this->entityManager->persist($target);
            $keys[] = $targetKey;
            ++$created;
            $priority += 10;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return ['created' => $created, 'skipped' => $skipped, 'keys' => $keys, 'rejected' => false];
    }
}
