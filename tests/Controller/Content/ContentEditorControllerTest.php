<?php

declare(strict_types=1);

namespace App\Tests\Controller\Content;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentEditorControllerTest extends WebTestCase
{
    public function testPermissionIsRequiredBeforeEditorPreview(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->persistEntry($client, [CmsPermission::ACCESS]);
        $client->loginUser($user);

        $client->request(
            'POST',
            '/admin/content/'.$entry->getId().'/editor/preview',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'invalid'],
            json_encode(['document' => $this->document([['type' => 'text', 'text' => 'x']])], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testPreviewEscapesXssAndDoesNotWrite(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->persistEntry($client);
        $original = $entry->getBody();
        $client->loginUser($user);
        [$token] = $this->editorState($client, $entry);

        $client->request(
            'POST',
            '/admin/content/'.$entry->getId().'/editor/preview',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['document' => $this->document([
                ['type' => 'text', 'text' => '<script>alert(1)</script>'],
                ['type' => 'link', 'text' => '<b>Link</b>', 'url' => 'https://example.test/path'],
            ])], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = $this->jsonResponse($client);
        self::assertStringNotContainsString('<script>', (string) $payload['html']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', (string) $payload['html']);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame($original, $em->find(ContentEntry::class, $entry->getId())?->getBody());
    }

    public function testPreviewRejectsUnsafeLinkAndUnsafeOrForeignMediaReferences(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->persistEntry($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $unsafe = (new MediaAsset())
            ->setModuleKey('content')->setOriginalName('unsafe.png')->setTitle('Unsafe')
            ->setMimeType('image/png')->setLocation('/uploads/media/../private.png');
        $foreign = (new MediaAsset())
            ->setModuleKey('video')->setOriginalName('foreign.png')->setTitle('Foreign')
            ->setMimeType('image/png')->setLocation('/uploads/media/content/foreign.png');
        $em->persist($unsafe);
        $em->persist($foreign);
        $em->flush();
        self::assertNotNull($unsafe->getId());
        self::assertNotNull($foreign->getId());

        $client->loginUser($user);
        [$token] = $this->editorState($client, $entry);

        foreach ([
            $this->document([['type' => 'link', 'text' => 'bad', 'url' => 'javascript:alert(1)']]),
            $this->document([['type' => 'media', 'assetId' => $unsafe->getId(), 'alt' => '', 'caption' => '']]),
            $this->document([['type' => 'media', 'assetId' => $foreign->getId(), 'alt' => '', 'caption' => '']]),
            $this->document([['type' => 'media', 'assetId' => 2147483647, 'alt' => '', 'caption' => '']]),
        ] as $document) {
            $client->request(
                'POST',
                '/admin/content/'.$entry->getId().'/editor/preview',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
                json_encode(['document' => $document], JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testAutosaveRequiresCsrfAndCreatesRevisionForDraft(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->persistEntry($client);
        $original = $entry->getBody();
        $client->loginUser($user);
        [$token, $updatedAt] = $this->editorState($client, $entry);
        $document = $this->document([
            ['type' => 'heading', 'level' => 2, 'text' => 'Autosave'],
            ['type' => 'text', 'text' => 'Gesicherter Entwurf'],
        ]);

        $client->request(
            'POST',
            '/admin/content/'.$entry->getId().'/editor/autosave',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'invalid'],
            json_encode(['document' => $document, 'updatedAt' => $updatedAt], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame($original, $em->find(ContentEntry::class, $entry->getId())?->getBody());

        $client->request(
            'POST',
            '/admin/content/'.$entry->getId().'/editor/autosave',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['document' => $document, 'updatedAt' => $updatedAt], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $payload = $this->jsonResponse($client);
        self::assertFalse($payload['unchanged']);
        self::assertStringStartsWith(ContentBlockDocument::PREFIX, (string) $payload['document']);
        self::assertNotSame($updatedAt, $payload['updatedAt']);

        $em->clear();
        $saved = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $saved);
        self::assertStringStartsWith(ContentBlockDocument::PREFIX, $saved->getBody());
        self::assertSame(1, $em->getRepository(ContentRevision::class)->count(['entry' => $saved]));
    }

    public function testAutosaveRejectsStaleAndPublishedWrites(): void
    {
        $client = static::createClient();
        [$user, $draft] = $this->persistEntry($client);
        $client->loginUser($user);
        [$token] = $this->editorState($client, $draft);
        $document = $this->document([['type' => 'text', 'text' => 'stale']]);

        $client->request(
            'POST',
            '/admin/content/'.$draft->getId().'/editor/autosave',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['document' => $document, 'updatedAt' => '2000-01-01T00:00:00+00:00'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(409);

        [$publishedUser, $published] = $this->persistEntry($client, [CmsPermission::ACCESS, CmsPermission::CONTENT], ContentEntry::STATUS_PUBLISHED);
        $client->loginUser($publishedUser);
        [$publishedToken, $publishedUpdatedAt] = $this->editorState($client, $published);

        $client->request(
            'POST',
            '/admin/content/'.$published->getId().'/editor/autosave',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $publishedToken],
            json_encode(['document' => $document, 'updatedAt' => $publishedUpdatedAt], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testDisabledContentModuleRejectsEditorEndpoint(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->persistEntry($client);
        $client->loginUser($user);
        [$token] = $this->editorState($client, $entry);
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        $contentWasEnabled = $modules->isEnabled('content');
        $gamingWasEnabled = $modules->isEnabled('gaming');

        try {
            if ($gamingWasEnabled) $modules->setEnabled('gaming', false);
            if ($contentWasEnabled) $modules->setEnabled('content', false);

            $client->request(
                'POST',
                '/admin/content/'.$entry->getId().'/editor/preview',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
                json_encode(['document' => $this->document([['type' => 'text', 'text' => 'hidden']])], JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($contentWasEnabled && !$modules->isEnabled('content')) $modules->setEnabled('content', true);
            if ($gamingWasEnabled && !$modules->isEnabled('gaming')) $modules->setEnabled('gaming', true);
        }
    }

    /**
     * @param list<string> $permissions
     * @return array{User,ContentEntry}
     */
    private function persistEntry(
        KernelBrowser $client,
        array $permissions = [CmsPermission::ACCESS, CmsPermission::CONTENT],
        string $status = ContentEntry::STATUS_DRAFT,
    ): array {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $user = (new User())
            ->setEmail('content-editor-'.$suffix.'@example.test')
            ->setDisplayName('Content editor')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entry = (new ContentEntry())
            ->setAuthor($user)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Editor '.$suffix)
            ->setSlug('content-editor-'.$suffix)
            ->setBody('Legacy original');
        if ($status !== ContentEntry::STATUS_DRAFT) {
            $entry->setStatus($status);
            $entry->synchronizePublication();
        }
        $em->persist($user);
        $em->persist($entry);
        $em->flush();

        return [$user, $entry];
    }

    /** @return array{string,string} */
    private function editorState(KernelBrowser $client, ContentEntry $entry): array
    {
        $crawler = $client->request('GET', '/admin/content/'.$entry->getId().'/edit');
        self::assertResponseIsSuccessful();
        $editor = $crawler->filter('[data-controller="content-editor"]');
        self::assertCount(1, $editor);
        $token = $editor->attr('data-content-editor-token-value');
        $updatedAt = $editor->attr('data-content-editor-updated-at-value');
        self::assertNotNull($token);
        self::assertNotNull($updatedAt);

        return [$token, $updatedAt];
    }

    /** @param list<array<string,mixed>> $blocks */
    private function document(array $blocks): string
    {
        return ContentBlockDocument::PREFIX.json_encode(
            ['version' => ContentBlockDocument::VERSION, 'blocks' => $blocks],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string,mixed> */
    private function jsonResponse(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
