<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExternalConnectorTarget;
use App\Entity\MediaAsset;
use App\Entity\MediaReplicationTask;
use App\Entity\ModuleStorageSetting;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorExecutor;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalConnectorTargetSource;
use App\ExternalConnector\ExternalMediaConnectorAdapter;
use App\ExternalConnector\ExternalMediaDispatcher;
use App\ExternalConnector\ExternalMediaObject;
use App\ExternalConnector\ExternalMediaUpload;
use App\Service\MediaReplicationRepairer;
use App\Service\MediaStorageCleanupJournal;
use App\Service\MediaUrlPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MediaReplicationRepairerSecurityTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            $this->removeTree($this->root);
        }
    }

    public function testUnsafeProviderLocationIsRejectedAndCompensated(): void
    {
        $root = $this->root();
        self::assertTrue(mkdir($root.'/var/media-repair', 0700, true));
        $stagedFilename = str_repeat('a', 32).'.bin';
        file_put_contents($root.'/var/media-repair/'.$stagedFilename, 'video');

        $asset = (new MediaAsset())
            ->setModuleKey('video')
            ->setStorageMode(ModuleStorageSetting::MODE_EXTERNAL)
            ->setLocation('https://media.example.test/video/original.mp4')
            ->setOriginalName('original.mp4')
            ->setTitle('Original')
            ->setMimeType('video/mp4')
            ->setFileSize(5);
        $task = (new MediaReplicationTask())
            ->setAsset($asset)
            ->setTargetKey('replica')
            ->setProviderKey('fake')
            ->setObjectKey('video/file.mp4')
            ->setStagedFilename($stagedFilename);

        $calls = new \ArrayObject();
        $dispatcher = $this->dispatcher($calls, 'javascript:alert(1)');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('remove');

        $repairer = new MediaReplicationRepairer(
            $dispatcher,
            $entityManager,
            new MediaUrlPolicy(),
            new MediaStorageCleanupJournal($root),
            $root,
        );

        self::assertFalse($repairer->repair($task));
        self::assertSame(['store:replica', 'delete:replica:video/file.mp4'], iterator_to_array($calls));
        self::assertCount(0, $asset->getReplicas());
    }

    /** @param \ArrayObject<int, string> $calls */
    private function dispatcher(\ArrayObject $calls, string $location): ExternalMediaDispatcher
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MEDIA)
            ->setTargetKey('replica')
            ->setProviderKey('fake')
            ->setDisplayName('Replica')
            ->setConfigurationReference('connector.replica')
            ->setRequired(false)
            ->setEnabled(true);

        $source = new class($target) implements ExternalConnectorTargetSource {
            public function __construct(private readonly ExternalConnectorTarget $target) {}
            public function enabledFor(string $capability): array
            {
                return $capability === ExternalConnectorTarget::CAPABILITY_MEDIA ? [$this->target] : [];
            }
        };
        $adapter = new class($calls, $location) implements ExternalMediaConnectorAdapter {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private readonly \ArrayObject $calls, private readonly string $location) {}
            public function providerKey(): string { return 'fake'; }
            public function supports(string $capability): bool { return $capability === ExternalConnectorTarget::CAPABILITY_MEDIA; }
            public function store(ExternalConnectorTargetDefinition $target, ExternalMediaUpload $upload): ExternalMediaObject
            {
                $this->calls[] = 'store:'.$target->targetKey;

                return new ExternalMediaObject($upload->objectKey, $this->location, 5);
            }
            public function delete(ExternalConnectorTargetDefinition $target, string $objectKey): void
            {
                $this->calls[] = 'delete:'.$target->targetKey.':'.$objectKey;
            }
        };
        $registry = new ExternalConnectorRegistry($source);
        $adapters = new ExternalConnectorAdapterRegistry([$adapter]);

        return new ExternalMediaDispatcher(new ExternalConnectorExecutor($registry, $adapters), $registry, $adapters);
    }

    private function root(): string
    {
        if ($this->root === null) {
            $this->root = sys_get_temp_dir().'/media-repair-security-'.bin2hex(random_bytes(8));
        }

        return $this->root;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.'/'.$entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
