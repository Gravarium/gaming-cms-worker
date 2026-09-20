<?php

declare(strict_types=1);

namespace App\Module;

use App\Entity\CmsModuleState;
use App\Repository\CmsModuleStateRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CmsModuleManager
{
    public function __construct(
        private CmsModuleCatalog $catalog,
        private CmsModuleStateRepository $states,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function isInstalled(string $key): bool
    {
        $this->definition($key);
        return $this->states->find($key)?->isInstalled() ?? true;
    }

    public function isEnabled(string $key): bool
    {
        $definition = $this->definition($key);
        if ($definition['required']) {
            return true;
        }

        return $this->isInstalled($key) && ($this->states->find($key)?->isEnabled() ?? true);
    }

    /** @return list<array<string, mixed>> */
    public function overview(): array
    {
        $catalog = $this->catalog->all();
        $result = [];
        foreach ($catalog as $key => $definition) {
            $state = $this->states->find($key);
            $installed = $this->isInstalled($key);
            $installedVersion = $installed ? ($state?->getInstalledVersion() ?? $definition['version']) : $state?->getInstalledVersion();
            $blockedBy = array_values(array_filter(
                $definition['dependencies'],
                fn (string $dependency): bool => !$this->isInstalled($dependency) || !$this->isEnabled($dependency),
            ));
            $dependents = [];
            foreach ($catalog as $candidate) {
                if (in_array($key, $candidate['dependencies'], true) && $this->isInstalled($candidate['key'])) {
                    $dependents[] = $candidate['key'];
                }
            }
            $result[] = $definition + [
                'installed' => $installed,
                'installedVersion' => $installedVersion,
                'updateAvailable' => $installed && $installedVersion !== null && version_compare($definition['version'], $installedVersion, '>'),
                'enabled' => $this->isEnabled($key),
                'blockedBy' => $blockedBy,
                'dependents' => $dependents,
                'dataRetained' => !$installed && $state !== null,
            ];
        }

        return $result;
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        $definition = $this->definition($key);
        if (!$this->isInstalled($key)) {
            throw new \DomainException('Das Modul muss zuerst installiert werden.');
        }
        if (!$enabled && $definition['required']) {
            throw new \DomainException('Das Kernmodul kann nicht deaktiviert werden.');
        }

        if ($enabled) {
            $missing = array_values(array_filter(
                $definition['dependencies'],
                fn (string $dependency): bool => !$this->isInstalled($dependency) || !$this->isEnabled($dependency),
            ));
            if ($missing !== []) {
                throw new \DomainException('Zuerst benötigte Module installieren und aktivieren: '.implode(', ', $missing));
            }
        } else {
            $dependents = $this->activeDependents($key);
            if ($dependents !== []) {
                throw new \DomainException('Zuerst abhängige Module deaktivieren: '.implode(', ', $dependents));
            }
        }

        $state = $this->state($key, $definition['version']);
        $state->setEnabled($enabled);
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    public function install(string $key): void
    {
        $definition = $this->definition($key);
        if ($this->isInstalled($key)) {
            throw new \DomainException('Das Modul ist bereits installiert.');
        }

        $missing = array_values(array_filter(
            $definition['dependencies'],
            fn (string $dependency): bool => !$this->isInstalled($dependency),
        ));
        if ($missing !== []) {
            throw new \DomainException('Zuerst benötigte Module installieren: '.implode(', ', $missing));
        }

        $state = $this->state($key, $definition['version']);
        $state->install($definition['version']);
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    public function update(string $key): void
    {
        $definition = $this->definition($key);
        if (!$this->isInstalled($key)) {
            throw new \DomainException('Das Modul ist nicht installiert.');
        }

        $state = $this->state($key, $definition['version']);
        $installedVersion = $state->getInstalledVersion() ?? $definition['version'];
        if (!version_compare($definition['version'], $installedVersion, '>')) {
            throw new \DomainException('Das Modul ist bereits aktuell.');
        }

        $state->updateVersion($definition['version']);
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    public function remove(string $key): void
    {
        $definition = $this->definition($key);
        if ($definition['required']) {
            throw new \DomainException('Das Kernmodul kann nicht deinstalliert werden.');
        }
        if (!$this->isInstalled($key)) {
            throw new \DomainException('Das Modul ist bereits deinstalliert.');
        }

        $dependents = $this->installedDependents($key);
        if ($dependents !== []) {
            throw new \DomainException('Zuerst abhängige Module deinstallieren: '.implode(', ', $dependents));
        }

        $state = $this->state($key, $definition['version']);
        $state->removePackage();
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    public function moduleForRoute(string $route): ?string
    {
        foreach ($this->catalog->all() as $module) {
            foreach ($module['routePrefixes'] as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    return $module['key'];
                }
            }
        }

        return null;
    }

    /** @return array{key:string,name:string,version:string,required:bool,dependencies:list<string>,routePrefixes:list<string>} */
    private function definition(string $key): array
    {
        return $this->catalog->all()[$key] ?? throw new \InvalidArgumentException('Unbekanntes CMS-Modul.');
    }

    private function state(string $key, string $version): CmsModuleState
    {
        $state = $this->states->find($key);
        if ($state !== null) {
            return $state;
        }

        return (new CmsModuleState())
            ->setModuleKey($key)
            ->updateVersion($version);
    }

    /** @return list<string> */
    private function activeDependents(string $key): array
    {
        return array_values(array_map(
            static fn (array $module): string => $module['key'],
            array_filter(
                $this->catalog->all(),
                fn (array $module): bool => in_array($key, $module['dependencies'], true) && $this->isEnabled($module['key']),
            ),
        ));
    }

    /** @return list<string> */
    private function installedDependents(string $key): array
    {
        return array_values(array_map(
            static fn (array $module): string => $module['key'],
            array_filter(
                $this->catalog->all(),
                fn (array $module): bool => in_array($key, $module['dependencies'], true) && $this->isInstalled($module['key']),
            ),
        ));
    }
}
