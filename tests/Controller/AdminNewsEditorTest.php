<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\NewsEditor\RichDocument;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminNewsEditorTest extends WebTestCase
{
    public function testAutosaveCreatesRevisionAndPreventsStaleOverwrite(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $hash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf); self::assertNotNull($updated); self::assertNotNull($hash);
        $endpoint = '/admin/news-editor/'.$entry->getId().'/autosave';
        $client->request('POST', $endpoint, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode(['document' => $this->document('Autosave'), 'updatedAt' => $updated, 'documentHash' => $hash], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $saved = json_decode($client->getResponse()->getContent() ?: '', true, 16, JSON_THROW_ON_ERROR);
        self::assertNotSame($updated, $saved['updatedAt']);
        self::assertNotSame($hash, $saved['documentHash']);
        self::assertStringContainsString('no-store', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $stored);
        self::assertSame('Autosave', $stored->getBody());
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $stored]));

        $client->request('POST', $endpoint, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode(['document' => $this->document('Stale'), 'updatedAt' => $updated, 'documentHash' => $hash], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        $client->request('POST', '/admin/news-editor/'.$entry->getId(), [
            '_token' => $csrf, 'updatedAt' => $saved['updatedAt'], 'documentHash' => $saved['documentHash'], 'document' => $this->document('Manuell'),
        ]);
        self::assertResponseRedirects('/admin/news-editor/'.$entry->getId());
        $em->clear();
        $stored = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $stored);
        self::assertSame('Manuell', $stored->getBody());
        self::assertCount(2, $em->getRepository(ContentRevision::class)->findBy(['entry' => $stored]));
    }

    public function testAutosaveRejectsInvalidDocumentAndCsrf(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $hash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf); self::assertNotNull($updated); self::assertNotNull($hash);
        $endpoint = '/admin/news-editor/'.$entry->getId().'/autosave';
        $payload = json_encode(['document' => RichDocument::PREFIX.'<script>', 'updatedAt' => $updated, 'documentHash' => $hash], JSON_THROW_ON_ERROR);
        $client->request('POST', $endpoint, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], $payload);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $endpoint, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'wrong'], $payload);
        self::assertResponseStatusCodeSame(403);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $unchanged = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $unchanged);
        self::assertSame('Legacy original', $unchanged->getBody());
        self::assertCount(0, $em->getRepository(ContentRevision::class)->findBy(['entry' => $unchanged]));
    }

    public function testNewNewsStartsInRichEditorWithInitialRevision(): void
    {
        $client = static::createClient();
        [$user] = $this->entry($client);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/new');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $title = 'Neuer Rich Draft '.bin2hex(random_bytes(4));
        $client->request('POST', '/admin/news-editor/new', ['_token' => $token, 'title' => $title]);
        self::assertResponseRedirects();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $created = $em->getRepository(ContentEntry::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(ContentEntry::class, $created);
        self::assertSame(ContentEntry::TYPE_NEWS, $created->getType());
        self::assertSame(ContentEntry::STATUS_DRAFT, $created->getStatus());
        self::assertSame($user->getId(), $created->getAuthor()?->getId());
        self::assertStringStartsWith(RichDocument::PREFIX, $created->getEditorDocument() ?? '');
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $created]));
        self::assertSame('/admin/news-editor/'.$created->getId(), $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Artikelentwurf speichern', $client->getResponse()->getContent() ?: '');
        $client->request('POST', '/admin/news-editor/new', ['_token' => $token, 'title' => $title]);
        self::assertResponseRedirects();
        $em->clear();
        $copies = $em->getRepository(ContentEntry::class)->findBy(['title' => $title]);
        self::assertCount(2, $copies);
        self::assertNotSame($copies[0]->getSlug(), $copies[1]->getSlug());
    }

    public function testNewNewsRejectsInvalidTitleAndCsrf(): void
    {
        $client = static::createClient();
        [$user] = $this->entry($client);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/new');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/admin/news-editor/new', ['_token' => $token, 'title' => str_repeat('x', 181)]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/admin/news-editor/new', ['_token' => 'wrong', 'title' => 'Should not exist']);
        self::assertResponseStatusCodeSame(403);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(ContentEntry::class)->findOneBy(['title' => 'Should not exist']));
    }

    public function testLegacyDraftOpensAndRichSaveCreatesRevision(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $client->loginUser($user);
        $legacyForm = $client->request('GET', '/admin/content/'.$entry->getId().'/edit');
        $oldToken = $legacyForm->filter('.content-editor')->attr('data-content-editor-token-value');
        self::assertNotNull($oldToken);
        $client->request('GET', '/admin/news-editor');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/admin/news-editor/'.$entry->getId(), $client->getResponse()->getContent() ?: '');
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('cms-rich:v2', $client->getResponse()->getContent() ?: '');
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $documentHash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf);
        self::assertNotNull($updated);
        self::assertNotNull($documentHash);
        $client->request('POST', '/admin/news-editor/'.$entry->getId(), [
            '_token' => $csrf, 'updatedAt' => $updated, 'documentHash' => $documentHash, 'document' => $this->document('Reicher Text'),
        ]);
        self::assertResponseRedirects('/admin/news-editor/'.$entry->getId());

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $saved);
        self::assertSame('Reicher Text', $saved->getBody());
        self::assertStringStartsWith(RichDocument::PREFIX, $saved->getEditorDocument() ?? '');
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $saved]));
        $client->request('POST', '/admin/news-editor/'.$entry->getId(), [
            '_token' => $csrf, 'updatedAt' => $updated, 'documentHash' => $documentHash,
            'document' => $this->document('Veraltete Änderung'),
        ]);
        self::assertResponseStatusCodeSame(422);
        $em->clear();
        $unchanged = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $unchanged);
        self::assertSame('Reicher Text', $unchanged->getBody());
        $metadata = $client->request('GET', '/admin/content/'.$entry->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $metadata->filter('textarea[name="content_entry[body]"]'));
        $client->request('POST', '/admin/content/'.$entry->getId().'/edit', ['content_entry' => ['body' => 'Alte Oberfläche']]);
        self::assertResponseStatusCodeSame(409);
        $em->clear();
        $protected = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $protected);
        self::assertSame('Reicher Text', $protected->getBody());
        $metadata = $client->request('GET', '/admin/content/'.$entry->getId().'/edit');
        $form = $metadata->selectButton('Artikeldaten speichern')->form();
        $form['content_entry[title]'] = 'Aktualisierter Titel';
        $client->submit($form);
        self::assertResponseRedirects('/admin/content/'.$entry->getId().'/edit');
        $em->clear();
        $retitled = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $retitled);
        self::assertSame('Aktualisierter Titel', $retitled->getTitle());
        self::assertSame('Reicher Text', $retitled->getBody());
        $client->request('POST', '/admin/content/'.$entry->getId().'/editor/autosave', [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $oldToken,
        ], json_encode(['document' => 'Alte Bearbeitung', 'updatedAt' => $retitled->getUpdatedAt()->format(DATE_ATOM)], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testPreviewEscapesMarkupAndDeniedWritesDoNotChangeArticle(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $documentHash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf);
        self::assertNotNull($updated);
        self::assertNotNull($documentHash);
        $client->request('POST', '/admin/news-editor/'.$entry->getId().'/preview', [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode(['document' => $this->document('<script>bad()</script>')], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $preview = json_decode($client->getResponse()->getContent() ?: '', true, 16, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('<script>', $preview['html']);
        self::assertStringContainsString('&lt;script&gt;', $preview['html']);

        $client->request('POST', '/admin/news-editor/'.$entry->getId(), [
            '_token' => 'invalid', 'updatedAt' => $updated, 'documentHash' => $documentHash, 'document' => $this->document('Forbidden'),
        ]);
        self::assertResponseStatusCodeSame(403);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $saved);
        self::assertSame('Legacy original', $saved->getBody());
    }

    public function testPermissionIsEnforced(): void
    {
        $client = static::createClient();
        [$reader, $entry] = $this->entry($client, [CmsPermission::ACCESS]);
        $client->loginUser($reader);
        $client->request('GET', '/admin/news-editor/'.$entry->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testPublishedStatusIsEnforced(): void
    {
        $client = static::createClient();
        [$manager, $published] = $this->entry($client, [CmsPermission::ACCESS, CmsPermission::CONTENT], ContentEntry::STATUS_PUBLISHED);
        $client->loginUser($manager);
        $client->request('GET', '/admin/news-editor/'.$published->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testMediaSearchOnlyListsUsableOwnedImages(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $safe = (new MediaAsset())->setModuleKey('content')->setMimeType('image/png')
            ->setOriginalName('rich-safe-'.$suffix.'.png')->setLocation('/uploads/media/rich-safe-'.$suffix.'.png');
        $other = (new MediaAsset())->setModuleKey('branding')->setMimeType('image/png')
            ->setOriginalName('rich-other-'.$suffix.'.png')->setLocation('/uploads/media/rich-other-'.$suffix.'.png');
        $em->persist($safe); $em->persist($other); $em->flush();
        $client->loginUser($user);
        $client->request('GET', '/admin/news-editor/'.$entry->getId().'/media?q=rich-');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 16, JSON_THROW_ON_ERROR);
        self::assertContains($safe->getId(), array_column($data['items'], 'id'));
        self::assertNotContains($other->getId(), array_column($data['items'], 'id'));
        $cache = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
        $client->request('GET', '/admin/news-editor/'.$entry->getId().'/media?id='.$safe->getId());
        self::assertResponseIsSuccessful();
        $lookup = json_decode($client->getResponse()->getContent() ?: '', true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([$safe->getId()], array_column($lookup['items'], 'id'));
        self::assertStringContainsString('no-store', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
        $client->request('GET', '/admin/news-editor/'.$entry->getId().'/media?id='.$other->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(['items' => []], json_decode($client->getResponse()->getContent() ?: '', true, 16, JSON_THROW_ON_ERROR));
        foreach (['0', '-1', '1%20', '9223372036854775808'] as $badId) {
            $client->request('GET', '/admin/news-editor/'.$entry->getId().'/media?id='.$badId);
            self::assertResponseStatusCodeSame(422);
        }
    }

    /** @param list<string> $permissions @return array{User,ContentEntry} */
    private function entry(KernelBrowser $client, array $permissions = [CmsPermission::ACCESS, CmsPermission::CONTENT], string $status = ContentEntry::STATUS_DRAFT): array
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $user = (new User())->setEmail('news-rich-'.$suffix.'@example.test')->setDisplayName('News editor')->setPermissions($permissions)->verifyEmail();
        $entry = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_NEWS)->setTitle('News '.$suffix)->setSlug('news-rich-'.$suffix)->setBody('Legacy original');
        if ($status !== ContentEntry::STATUS_DRAFT) {
            $entry->setStatus($status)->synchronizePublication();
        }
        $em->persist($user); $em->persist($entry); $em->flush();
        return [$user, $entry];
    }

    private function document(string $text): string
    {
        return RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => [
            ['type' => 'paragraph', 'content' => [['text' => $text, 'marks' => ['strong']]]],
        ]], JSON_THROW_ON_ERROR);
    }
}
