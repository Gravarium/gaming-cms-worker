<?php

declare(strict_types=1);

namespace App\Service;

use App\ExternalConnector\ExternalMediaDispatcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MediaStorageCleanupRepairer
{
    public function __construct(
        private MediaStorageCleanupJournal $journal,
        private ExternalMediaDispatcher $dispatcher,
        private S3ObjectStorage $objectStorage,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /** @return array{repaired:int,failed:int} */
    public function repairPending(int $limit = 100): array
    {
        $repaired = 0;
        $failed = 0;

        foreach ($this->journal->pending($limit) as $entry) {
            try {
                if ($entry['kind'] === MediaStorageCleanupJournal::KIND_CONNECTOR) {
                    if ($entry['targetKey'] === null) {
                        throw new \RuntimeException('Missing connector target.');
                    }
                    $this->dispatcher->deleteForTarget($entry['targetKey'], $entry['value']);
                } elseif ($entry['kind'] === MediaStorageCleanupJournal::KIND_LEGACY_S3) {
                    $this->objectStorage->delete($entry['value']);
                } else {
                    $path = $this->localPath($entry['value']);
                    if ((is_file($path) || is_link($path)) && !unlink($path)) {
                        throw new \RuntimeException('Local media cleanup failed.');
                    }
                }

                $this->journal->forget($entry['id']);
                ++$repaired;
            } catch (\Throwable) {
                ++$failed;
            }
        }

        return ['repaired' => $repaired, 'failed' => $failed];
    }

    private function localPath(string $location): string
    {
        if (!str_starts_with($location, '/uploads/media/')
            || str_contains($location, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $location) === 1
        ) {
            throw new \RuntimeException('Invalid local cleanup location.');
        }

        $relative = substr($location, strlen('/uploads/media/'));
        if ($relative === '' || mb_strlen($relative) > 500) {
            throw new \RuntimeException('Invalid local cleanup location.');
        }
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('Invalid local cleanup location.');
            }
        }

        $current = $this->projectDir;
        foreach (array_merge(['public', 'uploads', 'media'], array_slice($segments, 0, -1)) as $segment) {
            $current .= '/'.$segment;
            if (is_link($current)) {
                throw new \RuntimeException('Local media cleanup may not follow symbolic links.');
            }
        }

        return $this->projectDir.'/public/uploads/media/'.$relative;
    }
}
