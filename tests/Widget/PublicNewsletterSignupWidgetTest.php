<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicNewsletterSignupWidgetTest extends WebTestCase
{
    public function testPageBuilderPlacementOpensSignupAndDisappearsWhenNotificationsAreDisabled(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $originalState = $entityManager->find(CmsModuleState::class, 'notifications');
        $originalStateExisted = $originalState instanceof CmsModuleState;
        $originalEnabled = $originalState?->isEnabled() ?? true;
        $originalInstalled = $originalState?->isInstalled() ?? true;
        $originalVersion = $originalState?->getInstalledVersion();
        $originalLayout = $entityManager->find(PageLayout::class, 'home');
        $originalDocument = $originalLayout instanceof PageLayout ? $originalLayout->getDocument() : null;

        $admin = $this->user($client);
        $client->loginUser($admin);

        try {
            if ($originalLayout instanceof PageLayout) {
                $entityManager->remove($originalLayout);
                $entityManager->flush();
            }
            $this->setNotificationsEnabled($client, true);

            $editorCrawler = $client->request('GET', '/admin/layout/home');

            self::assertResponseIsSuccessful();
            $editor = $this->editorState($editorCrawler);
            $availableKeys = array_column($editor['widgets'], 'key');
            self::assertContains('notifications.newsletter-signup', $availableKeys);

            $token = $editorCrawler->filter('[data-layout-editor-token-value]')->attr('data-layout-editor-token-value');
            self::assertIsString($token);
            $document = $editor['document'];
            self::assertIsArray($document['widgets'] ?? null);
            $document['widgets'][] = [
                'id' => 'newsletter-signup-widget',
                'type' => 'notifications.newsletter-signup',
                'region' => 'main',
                'enabled' => true,
                'config' => [],
            ];

            $client->request(
                'POST',
                '/admin/layout/home/save',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
                json_encode(['version' => $editor['version'], 'document' => $document], JSON_THROW_ON_ERROR),
            );

            self::assertResponseIsSuccessful();
            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.widget-notifications-newsletter-signup a[href="/newsletter/subscribe"]');

            $signupLink = $client->getCrawler()->filter('.widget-notifications-newsletter-signup a')->link();
            $client->click($signupLink);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Newsletter abonnieren');

            $this->setNotificationsEnabled($client, false);
            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.widget-notifications-newsletter-signup');

            $disabledEditorCrawler = $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            $disabledEditor = $this->editorState($disabledEditorCrawler);
            $disabledKeys = array_column($disabledEditor['widgets'], 'key');
            self::assertNotContains('notifications.newsletter-signup', $disabledKeys);
            $disabledDocument = $disabledEditor['document'];
            self::assertIsArray($disabledDocument['widgets'] ?? null);
            self::assertContains(
                'notifications.newsletter-signup',
                array_column($disabledDocument['widgets'], 'type'),
            );

            $disabledToken = $disabledEditorCrawler->filter('[data-layout-editor-token-value]')->attr('data-layout-editor-token-value');
            self::assertIsString($disabledToken);
            $attemptedDocument = $disabledDocument;
            $attemptedDocument['widgets'][] = [
                'id' => 'disabled-newsletter-widget',
                'type' => 'notifications.newsletter-signup',
                'region' => 'main',
                'enabled' => true,
                'config' => [],
            ];

            $client->request(
                'POST',
                '/admin/layout/home/save',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $disabledToken],
                json_encode(['version' => $disabledEditor['version'], 'document' => $attemptedDocument], JSON_THROW_ON_ERROR),
            );

            self::assertResponseStatusCodeSame(422);
            $errorBody = $client->getResponse()->getContent();
            self::assertIsString($errorBody);
            $error = json_decode($errorBody, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($error);
            self::assertSame(
                'Nicht verfügbares Widget kann nicht neu angelegt oder verändert werden.',
                $error['error'] ?? null,
            );

            // The rejected write rolls back and Doctrine closes this manager; reset it before reading and restoring fixtures.
            $entityManager = $this->resetEntityManager($client);
            $storedLayout = $entityManager->find(PageLayout::class, 'home');
            self::assertInstanceOf(PageLayout::class, $storedLayout);
            self::assertSame($disabledDocument, $storedLayout->getDocument());
        } finally {
            $currentLayout = $entityManager->find(PageLayout::class, 'home');
            if ($currentLayout instanceof PageLayout) {
                $entityManager->remove($currentLayout);
            }
            if ($originalDocument !== null) {
                $restoredLayout = new PageLayout('home');
                $restoredLayout->replace($originalDocument);
                $entityManager->persist($restoredLayout);
            }

            $currentState = $entityManager->find(CmsModuleState::class, 'notifications');
            if (!$originalStateExisted) {
                if ($currentState instanceof CmsModuleState) {
                    $entityManager->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                if (!$originalInstalled) {
                    $currentState->removePackage();
                    if ($originalVersion !== null) {
                        $currentState->updateVersion($originalVersion);
                    }
                } else {
                    if (!$currentState->isInstalled()) {
                        $currentState->install($originalVersion ?? '1.0.0');
                    }
                    if ($originalVersion !== null) {
                        $currentState->updateVersion($originalVersion);
                    }
                    $currentState->setEnabled($originalEnabled);
                }
            }
            $entityManager->flush();
        }
    }

    /**
     * @return array{
     *     version: int,
     *     document: array<string, mixed>,
     *     widgets: list<array<string, mixed>>
     * }
     */
    private function editorState(Crawler $crawler): array
    {
        $json = $crawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value');
        self::assertIsString($json);
        $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsInt($state['version'] ?? null);
        self::assertIsArray($state['document'] ?? null);
        self::assertIsArray($state['widgets'] ?? null);

        /** @var list<array<string, mixed>> $widgets */
        $widgets = $state['widgets'];

        /** @var array<string, mixed> $document */
        $document = $state['document'];

        return ['version' => $state['version'], 'document' => $document, 'widgets' => $widgets];
    }

    private function setNotificationsEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'notifications');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('notifications');
            $entityManager->persist($state);
        } elseif (!$state->isInstalled()) {
            $state->install($state->getInstalledVersion() ?? '1.0.0');
        }

        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('newsletter-widget-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Newsletter widget editor')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::SETTINGS])
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function resetEntityManager(KernelBrowser $client): EntityManagerInterface
    {
        $registry = $client->getContainer()->get(ManagerRegistry::class);
        $registry->resetManager();
        $manager = $registry->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Doctrine did not reset the EntityManager.');
        }

        return $manager;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
