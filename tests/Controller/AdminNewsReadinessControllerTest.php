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

final class AdminNewsReadinessControllerTest extends WebTestCase
{
    public function testReportUsesUnsavedDocumentWithoutPersistingOrReturningItsText(): void
    {
        $client = static::createClient();
        [, $entry] = $this->entry($client);
        $entryId = $entry->getId();
        self::assertNotNull($entryId);
        $manager = $this->user([CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->getContainer()->get(EntityManagerInterface::class)->persist($manager);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/news-editor/'.$entryId);
        self::assertResponseIsSuccessful();
        $csrf = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $before = $em->find(ContentEntry::class, $entryId);
        self::assertInstanceOf(ContentEntry::class, $before);
        $beforeDocument = $before->getEditableDocument();
        $beforeUpdatedAt = $before->getUpdatedAt()->format(DATE_ATOM);
        $beforeRevisions = count($em->getRepository(ContentRevision::class)->findBy(['entry' => $before]));

        $secret = 'UNSAVED_PRIVATE_NEWS_TEXT_674';
        $document = $this->document([
            ['type' => 'heading', 'level' => 2, 'content' => [['text' => 'Aktueller Abschnitt', 'marks' => []]]],
            ['type' => 'paragraph', 'content' => [['text' => $secret.' steht nur im Browser.', 'marks' => []]]],
        ]);
        $client->request('POST', '/admin/news-editor/'.$entryId.'/readiness', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode(['document' => $document], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $report = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(11, $report['metrics']['wordCount']);
        self::assertStringNotContainsString($secret, (string) $client->getResponse()->getContent());

        $stored = $client->getContainer()->get(EntityManagerInterface::class)->find(ContentEntry::class, $entryId);
        self::assertInstanceOf(ContentEntry::class, $stored);
        self::assertSame($beforeDocument, $stored->getEditableDocument());
        self::assertSame($beforeUpdatedAt, $stored->getUpdatedAt()->format(DATE_ATOM));
        self::assertCount($beforeRevisions, $client->getContainer()->get(EntityManagerInterface::class)->getRepository(ContentRevision::class)->findBy(['entry' => $stored]));
    }

    public function testReportRequiresCsrfAndRejectsOversizedOrInvalidDocuments(): void
    {
        $client = static::createClient();
        [$manager, $entry] = $this->entry($client);
        $client->loginUser($manager);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        $csrf = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $endpoint = '/admin/news-editor/'.$entry->getId().'/readiness';
        $validDocument = $this->document([['type' => 'paragraph', 'content' => [['text' => 'Text', 'marks' => []]]]]);

        $client->request('POST', $endpoint, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'invalid'], json_encode(['document' => $validDocument], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Cache-Control', 'private, no-store, max-age=0');

        $client->request('POST', $endpoint, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], str_repeat('x', 131073));
        self::assertResponseStatusCodeSame(413);
        self::assertResponseHeaderSame('Cache-Control', 'private, no-store, max-age=0');

        $client->request('POST', $endpoint, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['document' => RichDocument::PREFIX.'<script>'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Cache-Control', 'private, no-store, max-age=0');
    }

    public function testReportUsesExistingContentPermission(): void
    {
        $client = static::createClient();
        [$reader, $draft] = $this->entry($client, [CmsPermission::ACCESS]);
        $client->loginUser($reader);
        $client->request('POST', '/admin/news-editor/'.$draft->getId().'/readiness');
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Cache-Control', 'private, no-store, max-age=0');
    }

    public function testReportRejectsPublishedEntries(): void
    {
        $client = static::createClient();
        [$manager, $published] = $this->entry($client, [CmsPermission::ACCESS, CmsPermission::CONTENT], ContentEntry::STATUS_PUBLISHED);
        $client->loginUser($manager);
        $client->request('POST', '/admin/news-editor/'.$published->getId().'/readiness');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<array<string,mixed>> $blocks */
    private function document(array $blocks): string
    {
        return RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $permissions @return array{User,ContentEntry} */
    private function entry(KernelBrowser $client, array $permissions = [CmsPermission::ACCESS, CmsPermission::CONTENT], string $status = ContentEntry::STATUS_DRAFT): array
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $author = $this->user([CmsPermission::ACCESS]);
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Readiness '.$suffix)
            ->setSlug('readiness-'.$suffix)
            ->setBody('Legacy original');
        if ($status !== ContentEntry::STATUS_DRAFT) {
            $entry->setStatus($status)->synchronizePublication();
        }
        $manager = $this->user($permissions);
        $em->persist($author);
        $em->persist($manager);
        $em->persist($entry);
        $em->flush();

        return [$manager, $entry];
    }

    /** @param list<string> $permissions */
    private function user(array $permissions): User
    {
        return (new User())
            ->setEmail('news-readiness-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('News editor')
            ->setPermissions($permissions)
            ->verifyEmail();
    }
}
