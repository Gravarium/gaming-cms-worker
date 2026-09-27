<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Download\DownloadPackage;
use App\Entity\PageLayout;
use App\Layout\LayoutValidator;
use App\Theme\ThemeRegistry;
use App\Widget\DownloadCatalogue\PublicDownloadCatalogueQuery;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicDownloadCatalogueWidgetTest extends WebTestCase
{
    public function testWidgetIsDiscoveredAndRendersOnlyBoundedPublicPackages(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $snapshot = $this->snapshot($entityManager);
        $packages = [];

        try {
            $this->enableDownloads($entityManager);
            $suffix = bin2hex(random_bytes(5));

            for ($number = 1; $number <= 14; ++$number) {
                $package = new DownloadPackage(
                    sprintf('00000000 WCP547 Public %02d %s', $number, $suffix),
                    sprintf('wcp547-%s-public-%02d', $suffix, $number),
                    'mod',
                );
                $entityManager->persist($package);
                $packages[] = $package;
            }

            $memberPackage = (new DownloadPackage(
                '99999999 WCP547 Member '.$suffix,
                'wcp547-'.$suffix.'-member',
                'file',
            ))->setVisibility('member');
            $adminPackage = (new DownloadPackage(
                '99999999 WCP547 Admin '.$suffix,
                'wcp547-'.$suffix.'-admin',
                'file',
            ))->setVisibility('admin');
            $disabledPackage = (new DownloadPackage(
                '99999999 WCP547 Disabled '.$suffix,
                'wcp547-'.$suffix.'-disabled',
                'file',
            ))->setEnabled(false);
            foreach ([$memberPackage, $adminPackage, $disabledPackage] as $package) {
                $entityManager->persist($package);
                $packages[] = $package;
            }
            $entityManager->flush();

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get('downloads.catalogue');
            self::assertNotNull($definition);
            self::assertSame('downloads', $definition->module);
            self::assertFalse($definition->multiple);
            self::assertTrue($registry->available('downloads.catalogue'));

            $data = $registry->data('downloads.catalogue', ['count' => 2]);
            self::assertIsArray($data['items'] ?? null);
            self::assertCount(2, $data['items']);
            /** @var list<DownloadPackage> $limitedItems */
            $limitedItems = $data['items'];
            self::assertSame(sprintf('00000000 WCP547 Public 01 %s', $suffix), $limitedItems[0]->getTitle());
            self::assertSame(sprintf('00000000 WCP547 Public 02 %s', $suffix), $limitedItems[1]->getTitle());

            $bounded = $client->getContainer()
                ->get(PublicDownloadCatalogueQuery::class)
                ->findPublicPackages(500);
            self::assertCount(PublicDownloadCatalogueQuery::MAX_ITEMS, $bounded);
            self::assertSame(sprintf('00000000 WCP547 Public 01 %s', $suffix), $bounded[0]->getTitle());
            self::assertSame(sprintf('00000000 WCP547 Public 02 %s', $suffix), $bounded[1]->getTitle());

            $this->saveHomeLayout($client, 2);
            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.widget-downloads-catalogue');
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString(sprintf('00000000 WCP547 Public 01 %s', $suffix), $html);
            self::assertStringContainsString(sprintf('00000000 WCP547 Public 02 %s', $suffix), $html);
            self::assertStringNotContainsString(sprintf('00000000 WCP547 Public 03 %s', $suffix), $html);
            self::assertStringNotContainsString($memberPackage->getSlug(), $html);
            self::assertStringNotContainsString($adminPackage->getSlug(), $html);
            self::assertStringNotContainsString($disabledPackage->getSlug(), $html);
            self::assertStringContainsString('/downloads/wcp547-'.$suffix.'-public-01', $html);
            self::assertStringContainsString('href="/downloads"', $html);

            $emptyMarkup = $client->getContainer()->get(Environment::class)->render(
                'widget/public_download_catalogue.html.twig',
                ['config' => ['count' => 2], 'data' => ['items' => []]],
            );
            self::assertStringContainsString('Zurzeit sind keine öffentlichen Downloads verfügbar.', $emptyMarkup);
        } finally {
            $this->restore($client, $snapshot, $packages);
        }
    }

    public function testDisabledDownloadsHideTheWidgetAndItsData(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $snapshot = $this->snapshot($entityManager);
        $packages = [];

        try {
            $this->enableDownloads($entityManager);
            $suffix = bin2hex(random_bytes(5));
            $package = new DownloadPackage(
                '00000000 WCP547 Gate '.$suffix,
                'wcp547-'.$suffix.'-gate',
                'mod',
            );
            $entityManager->persist($package);
            $packages[] = $package;
            $entityManager->flush();
            $this->saveHomeLayout($client, 6);

            $state = $entityManager->find(CmsModuleState::class, 'downloads');
            self::assertInstanceOf(CmsModuleState::class, $state);
            $state->setEnabled(false);
            $entityManager->flush();

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available('downloads.catalogue'));
            self::assertSame([], $registry->data('downloads.catalogue', ['count' => 6]));
            self::assertSame(
                [],
                $client->getContainer()->get(PublicDownloadCatalogueQuery::class)->findPublicPackages(6),
            );

            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.widget-downloads-catalogue');
            self::assertStringNotContainsString($package->getSlug(), (string) $client->getResponse()->getContent());
        } finally {
            $this->restore($client, $snapshot, $packages);
        }
    }

    /**
     * @return array{stateExists: bool, stateEnabled: ?bool, layoutExists: bool, layoutDocument: ?array}
     */
    private function snapshot(EntityManagerInterface $entityManager): array
    {
        $state = $entityManager->find(CmsModuleState::class, 'downloads');
        $layout = $entityManager->find(PageLayout::class, 'home');

        return [
            'stateExists' => $state instanceof CmsModuleState,
            'stateEnabled' => $state?->isEnabled(),
            'layoutExists' => $layout instanceof PageLayout,
            'layoutDocument' => $layout?->getDocument(),
        ];
    }

    private function enableDownloads(EntityManagerInterface $entityManager): void
    {
        $state = $entityManager->find(CmsModuleState::class, 'downloads');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())
                ->setModuleKey('downloads')
                ->updateVersion('1.0.0');
        }

        $state->setEnabled(true);
        $entityManager->persist($state);
        $entityManager->flush();
    }

    private function saveHomeLayout(KernelBrowser $client, int $count): void
    {
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $validator = $container->get(LayoutValidator::class);
        $document = $validator->defaults('nebula')->toArray();
        $document['widgets'][] = [
            'id' => 'download-catalogue-test',
            'type' => 'downloads.catalogue',
            'region' => $container->get(ThemeRegistry::class)->get('nebula')->fallbackRegion,
            'enabled' => true,
            'config' => ['count' => $count],
        ];
        $record = $entityManager->find(PageLayout::class, 'home') ?? new PageLayout('home');
        $record->replace($validator->validate($document)->toArray());
        $entityManager->persist($record);
        $entityManager->flush();
    }

    /**
     * @param array{stateExists: bool, stateEnabled: ?bool, layoutExists: bool, layoutDocument: ?array} $snapshot
     * @param list<DownloadPackage> $packages
     */
    private function restore(KernelBrowser $client, array $snapshot, array $packages): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        foreach ($packages as $package) {
            $id = $package->getId();
            if ($id !== null) {
                $persisted = $entityManager->find(DownloadPackage::class, $id);
                if ($persisted instanceof DownloadPackage) {
                    $entityManager->remove($persisted);
                }
            }
        }

        $layout = $entityManager->find(PageLayout::class, 'home');
        if ($snapshot['layoutExists']) {
            $layout ??= new PageLayout('home');
            $layout->replace($snapshot['layoutDocument'] ?? []);
            $entityManager->persist($layout);
        } elseif ($layout instanceof PageLayout) {
            $entityManager->remove($layout);
        }

        $state = $entityManager->find(CmsModuleState::class, 'downloads');
        if ($snapshot['stateExists'] && $state instanceof CmsModuleState && $snapshot['stateEnabled'] !== null) {
            $state->setEnabled($snapshot['stateEnabled']);
        } elseif (!$snapshot['stateExists'] && $state instanceof CmsModuleState) {
            $entityManager->remove($state);
        }

        $entityManager->flush();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
