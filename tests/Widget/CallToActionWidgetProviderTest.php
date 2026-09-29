<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CallToActionWidgetProviderTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('cta-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('CTA test')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::SETTINGS])
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();
        $client->loginUser($user);

        return $client;
    }

    private function clearLayout(KernelBrowser $client): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $record = $entityManager->find(PageLayout::class, 'home');
        if ($record !== null) {
            $entityManager->remove($record);
            $entityManager->flush();
        }
        $entityManager->clear();
    }

    public function testEditorDiscoversFixedDestinationsAndPreviewEscapesCopy(): void
    {
        $client = $this->client();
        $this->clearLayout($client);
        $previousModuleStates = [];

        try {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            foreach (['content', 'gaming', 'media', 'video'] as $module) {
                $state = $entityManager->find(CmsModuleState::class, $module);
                if ($state !== null) {
                    $previousModuleStates[$module] = $state->isEnabled();
                    $state->setEnabled(true);
                }
            }
            $entityManager->flush();

            $crawler = $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            $token = $crawler->filter('[data-layout-editor-token-value]')->attr('data-layout-editor-token-value');
            $editor = json_decode(
                (string) $crawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $definitions = [];
            foreach ($editor['widgets'] as $definition) {
                $definitions[$definition['key']] = $definition;
            }

            $types = [
                'content.call-to-action',
                'gaming.call-to-action',
                'video.call-to-action',
            ];
            foreach ($types as $type) {
                self::assertArrayHasKey($type, $definitions);
                self::assertSame('text', $definitions[$type]['schema']['buttonLabel']['type']);
                self::assertSame(80, $definitions[$type]['schema']['buttonLabel']['max']);
            }

            $document = $client->getContainer()->get(LayoutValidator::class)->defaults('nebula')->toArray();
            $document['widgets'][] = [
                'id' => 'cta-news-01',
                'type' => 'content.call-to-action',
                'region' => 'main',
                'enabled' => true,
                'config' => [
                    'title' => '<script>alert("cta-title")</script>',
                    'text' => '<img src=x onerror=alert(1)>',
                    'buttonLabel' => 'News <svg onload=alert(1)>',
                ],
            ];
            $document['widgets'][] = [
                'id' => 'cta-gaming-01',
                'type' => 'gaming.call-to-action',
                'region' => 'main',
                'enabled' => true,
                'config' => [
                    'title' => 'Gilden finden',
                    'text' => 'Spiele gemeinsam.',
                    'buttonLabel' => 'Gilden ansehen',
                ],
            ];
            $document['widgets'][] = [
                'id' => 'cta-video-01',
                'type' => 'video.call-to-action',
                'region' => 'main',
                'enabled' => true,
                'config' => [
                    'title' => 'Videos ansehen',
                    'text' => 'Neu in der Mediathek.',
                    'buttonLabel' => 'Videos öffnen',
                ],
            ];

            $payload = json_encode(['version' => 0, 'document' => $document], JSON_THROW_ON_ERROR);
            $client->request(
                'POST',
                '/admin/layout/home/preview',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
                $payload,
            );

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.widget-content-call-to-action a[href="/news"]');
            self::assertSelectorExists('.widget-gaming-call-to-action a[href="/gaming"]');
            self::assertSelectorExists('.widget-video-call-to-action a[href="/videos"]');
            self::assertSelectorTextContains(
                '.widget-content-call-to-action h2',
                '<script>alert("cta-title")</script>',
            );
            self::assertSelectorTextContains(
                '.widget-call-to-action-copy',
                '<img src=x onerror=alert(1)>',
            );
            self::assertSelectorTextContains(
                '.widget-content-call-to-action a',
                'News <svg onload=alert(1)>',
            );
            self::assertStringNotContainsString(
                '<script>alert("cta-title")</script>',
                (string) $client->getResponse()->getContent(),
            );
            self::assertStringNotContainsString(
                '<img src=x onerror=alert(1)>',
                (string) $client->getResponse()->getContent(),
            );

            foreach ([
                ['url', 'javascript:alert(1)'],
                ['route', 'app_admin_settings'],
                ['buttonLabel', str_repeat('x', 81)],
            ] as [$field, $value]) {
                $invalid = $document;
                $invalid['widgets'][1]['config'][$field] = $value;
                $client->request(
                    'POST',
                    '/admin/layout/home/preview',
                    [],
                    [],
                    ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
                    json_encode(['version' => 0, 'document' => $invalid], JSON_THROW_ON_ERROR),
                );
                self::assertResponseStatusCodeSame(422);
            }
        } finally {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            foreach ($previousModuleStates as $module => $wasEnabled) {
                $state = $entityManager->find(CmsModuleState::class, $module);
                if ($state !== null) {
                    $state->setEnabled($wasEnabled);
                }
            }
            $entityManager->flush();
            $this->clearLayout($client);
        }
    }

    public function testDisabledTargetModuleHidesWholeStoredWidget(): void
    {
        $client = $this->client();
        $this->clearLayout($client);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);

        try {
            $validator = $client->getContainer()->get(LayoutValidator::class);
            $document = $validator->defaults('nebula')->toArray();
            $document['widgets'][] = [
                'id' => 'cta-video-disabled',
                'type' => 'video.call-to-action',
                'region' => 'main',
                'enabled' => true,
                'config' => [
                    'title' => 'Nur für den Test sichtbare Video-CTA',
                    'text' => 'Dieser Inhalt muss verborgen bleiben.',
                    'buttonLabel' => 'Videos öffnen',
                ],
            ];
            $record = new PageLayout('home');
            $record->replace($validator->validate($document)->toArray());
            $entityManager->persist($record);

            $state = $entityManager->find(CmsModuleState::class, 'video')
                ?? (new CmsModuleState())->setModuleKey('video')->updateVersion('1.0.0');
            $state->setEnabled(false);
            $entityManager->persist($state);
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.widget-video-call-to-action');
            self::assertStringNotContainsString(
                'Nur für den Test sichtbare Video-CTA',
                (string) $client->getResponse()->getContent(),
            );

            $crawler = $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            $editor = json_decode(
                (string) $crawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $types = array_column($editor['widgets'], 'key');
            self::assertNotContains('video.call-to-action', $types);
            self::assertSame('video.call-to-action', $editor['document']['widgets'][1]['type']);
        } finally {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $state = $entityManager->find(CmsModuleState::class, 'video');
            if ($state !== null) {
                $entityManager->remove($state);
                $entityManager->flush();
            }
            $this->clearLayout($client);
        }
    }
}
