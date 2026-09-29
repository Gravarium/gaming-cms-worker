<?php

declare(strict_types=1);

namespace App\Tests\Layout;

use App\Entity\PageLayout;
use App\Layout\LayoutDocument;
use App\Layout\LayoutStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LayoutStoreBoundaryTest extends KernelTestCase
{
    public function testOversizedPersistedWidgetAndConfigCollectionsFallBackToDefaults(): void
    {
        $row = [
            'id' => 'legacy-widget-1',
            'type' => 'extension.legacy',
            'region' => 'hero',
            'enabled' => false,
            'config' => ['opaque' => 'retained'],
        ];

        $document = $this->document(array_fill(0, 61, $row));
        $this->assertDefaults($this->loadStoredDocument($document));

        $config = array_fill_keys(
            array_map(static fn (int $index): string => 'setting-'.$index, range(1, 65)),
            'value',
        );
        $document = $this->document([array_merge($row, ['config' => $config])]);
        $this->assertDefaults($this->loadStoredDocument($document));

        $oversizedKeyConfig = [str_repeat('k', 129) => 'value'];
        $document = $this->document([array_merge($row, ['config' => $oversizedKeyConfig])]);
        $this->assertDefaults($this->loadStoredDocument($document));
    }

    public function testOversizedPersistedValuesAndAggregateConfigFallBackToDefaults(): void
    {
        $row = [
            'id' => 'legacy-widget-1',
            'type' => 'extension.legacy',
            'region' => 'hero',
            'enabled' => false,
            'config' => ['opaque' => str_repeat('x', 16001)],
        ];
        $this->assertDefaults($this->loadStoredDocument($this->document([$row])));

        $rows = [];
        for ($index = 0; $index < 42; ++$index) {
            $rows[] = [
                'id' => 'legacy-widget-'.$index,
                'type' => 'extension.legacy',
                'region' => 'hero',
                'enabled' => false,
                'config' => [
                    'first' => str_repeat('a', 16000),
                    'second' => str_repeat('b', 16000),
                    'third' => str_repeat('c', 16000),
                ],
            ];
        }
        $this->assertDefaults($this->loadStoredDocument($this->document($rows)));
    }

    public function testPreservesDormantWidgetConfigurationWithinBounds(): void
    {
        $document = $this->document([[
            'id' => 'legacy-widget-1',
            'type' => 'extension.legacy',
            'region' => 'hero',
            'enabled' => false,
            'config' => ['opaque' => 'retained'],
        ]]);

        $loaded = $this->loadStoredDocument($document);
        self::assertSame('extension.legacy', $loaded->widgets[0]['type']);
        self::assertSame(['opaque' => 'retained'], $loaded->widgets[0]['config']);
    }

    /** @param list<array<string, mixed>> $widgets
     * @return array<string, mixed>
     */
    private function document(array $widgets): array
    {
        return ['schema' => 1, 'theme' => 'nebula', 'options' => [], 'widgets' => $widgets];
    }

    /** @param array<string, mixed> $document */
    private function loadStoredDocument(array $document): LayoutDocument
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $context = 'layout-boundary-'.bin2hex(random_bytes(8));
        $record = new PageLayout($context);
        $record->replace($document);
        $entityManager->persist($record);
        $entityManager->flush();

        try {
            return $container->get(LayoutStore::class)->load($context);
        } finally {
            $entityManager->remove($record);
            $entityManager->flush();
        }
    }

    private function assertDefaults(LayoutDocument $document): void
    {
        self::assertSame('core.welcome', $document->widgets[0]['type'] ?? null);
    }
}
