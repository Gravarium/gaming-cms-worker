<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ExtensionCapabilityAdministration
{
    /** @var array<string, array{label: string, description: string}> */
    private const CAPABILITY_DETAILS = [
        'content.read' => [
            'label' => 'Inhalte lesen',
            'description' => 'Liest News und Seiten über die freigegebenen CMS-Schnittstellen.',
        ],
        'content.write' => [
            'label' => 'Inhalte ändern',
            'description' => 'Erstellt oder ändert Inhalte über die freigegebenen CMS-Schnittstellen.',
        ],
        'media.read' => [
            'label' => 'Medien lesen',
            'description' => 'Liest Medieninformationen über die freigegebenen CMS-Schnittstellen.',
        ],
        'media.write' => [
            'label' => 'Medien ändern',
            'description' => 'Lädt Medien über die freigegebenen CMS-Schnittstellen hoch oder ändert sie.',
        ],
        'notifications.send' => [
            'label' => 'Benachrichtigungen senden',
            'description' => 'Sendet Benachrichtigungen über die freigegebenen CMS-Schnittstellen.',
        ],
        'http.outbound' => [
            'label' => 'Ausgehende HTTP-Anfragen',
            'description' => 'Kontaktiert externe Hosts; die vorhandene Ziel- und Netzwerkprüfung gilt weiterhin.',
        ],
        'scheduler.register' => [
            'label' => 'Geplante Aufgaben registrieren',
            'description' => 'Registriert geplante Arbeit über den begrenzten Scheduler-Vertrag.',
        ],
        'settings.read' => [
            'label' => 'Einstellungen lesen',
            'description' => 'Liest freigegebene Website-Einstellungen über den CMS-Vertrag.',
        ],
        'settings.write' => [
            'label' => 'Einstellungen ändern',
            'description' => 'Ändert freigegebene Website-Einstellungen über den CMS-Vertrag.',
        ],
    ];

    public function __construct(
        private ExtensionPackageVerifier $verifier,
        private ExtensionPermissionStore $permissions,
        private ExtensionCapabilityPolicy $policy,
        #[Autowire('%kernel.project_dir%/var/extensions/installed')]
        private string $installRoot,
    ) {
    }

    /**
     * @return array{
     *     type: string,
     *     typeLabel: string,
     *     key: string,
     *     name: string,
     *     version: string,
     *     capabilities: list<array{key: string, label: string, description: string, approved: bool}>
     * }
     */
    public function review(string $type, string $key): array
    {
        $manifest = $this->verifiedManifest($type, $key);
        $approved = $this->permissions->approved($manifest);
        $capabilities = [];

        foreach ($manifest->capabilities as $capability) {
            if (!$this->policy->isGrantable($capability) || !isset(self::CAPABILITY_DETAILS[$capability])) {
                throw new \DomainException('Extension capability metadata is unavailable.');
            }

            $details = self::CAPABILITY_DETAILS[$capability];
            $capabilities[] = [
                'key' => $capability,
                'label' => $details['label'],
                'description' => $details['description'],
                'approved' => in_array($capability, $approved, true),
            ];
        }

        return [
            'type' => $manifest->type,
            'typeLabel' => $manifest->type === 'theme' ? 'Theme' : 'Modul',
            'key' => $manifest->key,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'capabilities' => $capabilities,
        ];
    }

    public function set(string $type, string $key, string $capability, bool $approved): void
    {
        $manifest = $this->verifiedManifest($type, $key);
        if (!$this->policy->isGrantable($capability)
            || !in_array($capability, $manifest->capabilities, true)
            || !isset(self::CAPABILITY_DETAILS[$capability])
        ) {
            throw new \DomainException('Capability was not requested by this verified package.');
        }

        if ($approved) {
            $this->permissions->grant($manifest, $capability);

            return;
        }

        $this->permissions->revoke($manifest, $capability);
    }

    private function verifiedManifest(string $type, string $key): ExtensionManifest
    {
        if (!in_array($type, ['module', 'theme'], true)
            || preg_match('/\A[a-z][a-z0-9-]{1,39}\z/', $key) !== 1
        ) {
            throw new \DomainException('Extension package identity is invalid.');
        }

        $configuredRoot = rtrim($this->installRoot, DIRECTORY_SEPARATOR);
        if ($configuredRoot === '' || is_link($configuredRoot)) {
            throw new \DomainException('Extension install root may not be a symbolic link.');
        }

        $root = realpath($configuredRoot);
        if ($root === false || !is_dir($root)) {
            throw new \DomainException('Extension install root is unavailable.');
        }

        $typePath = $root.DIRECTORY_SEPARATOR.$type;
        if (is_link($typePath)) {
            throw new \DomainException('Extension type directory may not be a symbolic link.');
        }
        $typeRoot = realpath($typePath);
        if ($typeRoot === false || !is_dir($typeRoot) || dirname($typeRoot) !== $root) {
            throw new \DomainException('Extension type directory is unavailable.');
        }

        $packagePath = $typeRoot.DIRECTORY_SEPARATOR.$key;
        if (is_link($packagePath)) {
            throw new \DomainException('Extension package directory may not be a symbolic link.');
        }
        $packageDirectory = realpath($packagePath);
        if ($packageDirectory === false || !is_dir($packageDirectory) || dirname($packageDirectory) !== $typeRoot) {
            throw new \DomainException('Extension package directory is unavailable.');
        }

        $manifest = $this->verifier->verify($packageDirectory);
        if ($manifest->type !== $type || $manifest->key !== $key) {
            throw new \DomainException('Extension manifest identity does not match its installed path.');
        }

        return $manifest;
    }
}
