<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\NewsEditor\RichDocument;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminNewsEditorTest extends WebTestCase
{
    public function testLegacyDraftOpensAndRichSaveCreatesRevision(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $client->loginUser($user);
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
