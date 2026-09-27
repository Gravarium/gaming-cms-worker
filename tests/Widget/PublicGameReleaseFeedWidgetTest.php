<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Layout\LayoutRenderer;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Widget\PublicGameReleaseFeedWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicGameReleaseFeedWidgetTest extends WebTestCase
{
    public function testPageBuilderDiscoversTheAccessibleFeedLinkAndRespectsGamingModule(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);

        try {
            $this->setGamingModuleEnabled($client, true);
            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(PublicGameReleaseFeedWidgetProvider::KEY);

            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('gaming', $definition->module);
            self::assertSame('widget/game_release_feed.html.twig', $definition->template);
            self::assertTrue($registry->available(PublicGameReleaseFeedWidgetProvider::KEY));
            self::assertContains(
                PublicGameReleaseFeedWidgetProvider::KEY,
                array_map(
                    static fn (WidgetDefinition $widget): string => $widget->key,
                    $registry->availableDefinitions(),
                ),
            );

            $validator = $container->get(LayoutValidator::class);
            $document = $validator->defaults('nebula')->toArray();
            $region = $document['widgets'][0]['region'];
            $document['widgets'][] = [
                'id' => 'game-release-feed',
                'type' => PublicGameReleaseFeedWidgetProvider::KEY,
                'region' => $region,
                'enabled' => true,
                'config' => [],
            ];
            $validated = $validator->validate($document);
            $view = $container->get(LayoutRenderer::class)->view($validated);
            self::assertSame(
                PublicGameReleaseFeedWidgetProvider::KEY,
                $view['regions'][$region][1]['definition']->key,
            );

            $markup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'game-release-feed'],
                'config' => [],
                'data' => $registry->data(PublicGameReleaseFeedWidgetProvider::KEY, []),
            ]);
            self::assertStringContainsString('href="/games/releases.rss"', $markup);
            self::assertStringContainsString('RSS-Feed für Spielveröffentlichungen abonnieren', $markup);
            self::assertStringContainsString('aria-label="Spielveröffentlichungen"', $markup);

            $this->setGamingModuleEnabled($client, false);
            self::assertFalse($registry->available(PublicGameReleaseFeedWidgetProvider::KEY));
            self::assertSame([], $registry->data(PublicGameReleaseFeedWidgetProvider::KEY, []));
            $hiddenView = $container->get(LayoutRenderer::class)->view($validated);
            self::assertCount(1, $hiddenView['regions'][$region]);
        } finally {
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /** @return array{exists: bool, enabled: bool} */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the release feed widget test.');
        }

        $state = $this->entityManager($client)->find(CmsModuleState::class, 'gaming');

        return ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setGamingModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /** @param array{exists: bool, enabled: bool} $snapshot */
    private function restoreGamingModuleState(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if (!$snapshot['exists']) {
            if ($state instanceof CmsModuleState) {
                $entityManager->remove($state);
                $entityManager->flush();
            }

            return;
        }

        if ($state instanceof CmsModuleState && $state->isEnabled() !== $snapshot['enabled']) {
            $state->setEnabled($snapshot['enabled']);
            $entityManager->flush();
        }
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
