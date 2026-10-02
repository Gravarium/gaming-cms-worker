<?php

declare(strict_types=1);

namespace App\Tests\MediaAccessibility;

use App\Entity\MediaAsset;
use App\MediaAccessibility\MediaAccessibilityReport;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MediaAccessibilityReportTest extends KernelTestCase
{
    public function testReportCountsOnlyActiveImagesAndUsesStableBoundedPages(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $report = static::getContainer()->get(MediaAccessibilityReport::class);
        $baseline = $report->page(1)['total'];

        $missing = [];
        for ($index = 0; $index < 26; ++$index) {
            $missing[] = $this->asset('image-'.$index.'.png', 'image/png', null);
        }
        $withAltText = $this->asset('image-with-alt.png', 'image/png', 'A useful description');
        $pending = $this->asset('image-pending.png', 'image/png', null, true);
        $nonImage = $this->asset('document.txt', 'text/plain', null);
        $fixtures = [...$missing, $withAltText, $pending, $nonImage];

        try {
            foreach ($fixtures as $asset) {
                $entityManager->persist($asset);
            }
            $entityManager->flush();

            $first = $report->page(1);
            $second = $report->page(2);
            self::assertSame($baseline + 26, $first['total']);
            self::assertSame($first['total'], $second['total']);
            self::assertCount(MediaAccessibilityReport::PAGE_SIZE, $first['assets']);
            self::assertSame(
                min(MediaAccessibilityReport::MAX_PAGE, max(1, (int) ceil(($baseline + 26) / MediaAccessibilityReport::PAGE_SIZE))),
                $first['pages'],
            );

            $firstIds = $this->ids($first['assets']);
            $secondIds = $this->ids($second['assets']);
            self::assertSame([], array_values(array_intersect($firstIds, $secondIds)));
            self::assertSame($firstIds, $this->sortedDescending($firstIds));
            self::assertSame($secondIds, $this->sortedDescending($secondIds));

            $reportedIds = [...$firstIds, ...$secondIds];
            self::assertCount(26, array_intersect($this->ids($missing), $reportedIds));
            self::assertNotContains($withAltText->getId(), $reportedIds);
            self::assertNotContains($pending->getId(), $reportedIds);
            self::assertNotContains($nonImage->getId(), $reportedIds);
        } finally {
            foreach ($fixtures as $asset) {
                $entityManager->remove($asset);
            }
            $entityManager->flush();
        }
    }

    public function testReportRejectsPagePastTheLastPage(): void
    {
        self::bootKernel();
        $report = static::getContainer()->get(MediaAccessibilityReport::class);
        $first = $report->page(1);
        if ($first['pages'] === MediaAccessibilityReport::MAX_PAGE) {
            self::markTestSkipped('The test database exceeds the report page cap.');
        }

        $this->expectException(\OutOfRangeException::class);
        $report->page($first['pages'] + 1);
    }

    #[DataProvider('invalidPages')]
    public function testReportRejectsInvalidPageBounds(int $page): void
    {
        self::bootKernel();
        $report = static::getContainer()->get(MediaAccessibilityReport::class);

        $this->expectException(\InvalidArgumentException::class);
        $report->page($page);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPages(): iterable
    {
        yield 'zero' => [0];
        yield 'above maximum' => [MediaAccessibilityReport::MAX_PAGE + 1];
    }

    private function asset(string $name, string $mimeType, ?string $altText, bool $pending = false): MediaAsset
    {
        $suffix = bin2hex(random_bytes(6));
        $asset = (new MediaAsset())
            ->setModuleKey('content')
            ->setStorageMode('internal')
            ->setLocation('/uploads/media/content/report-'.$suffix.'-'.$name)
            ->setOriginalName($name)
            ->setTitle('Report fixture '.$suffix)
            ->setMimeType($mimeType)
            ->setFileSize(64)
            ->setAltText($altText);

        if ($pending) {
            $asset->markDeletionPending();
        }

        return $asset;
    }

    /** @param list<MediaAsset> $assets
     * @return list<int>
     */
    private function ids(array $assets): array
    {
        return array_map(
            static fn (MediaAsset $asset): int => $asset->getId() ?? throw new \LogicException('Persisted media asset expected.'),
            $assets,
        );
    }

    /** @param list<int> $ids
     * @return list<int>
     */
    private function sortedDescending(array $ids): array
    {
        rsort($ids, SORT_NUMERIC);

        return $ids;
    }
}
