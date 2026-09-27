<?php

declare(strict_types=1);

namespace App\Tests\Controller\Content;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScheduledContentAutosaveBoundaryTest extends WebTestCase
{
    public function testAutosaveRejectsScheduledContentWithoutChangingItsPublicationState(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $scheduledAt = new \DateTimeImmutable('+2 days');
        $scheduledUnpublishAt = $scheduledAt->modify('+5 days');
        $originalDocument = ContentBlockDocument::PREFIX.json_encode(
            [
                'version' => ContentBlockDocument::VERSION,
                'blocks' => [['type' => 'text', 'text' => 'Original scheduled document']],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $user = (new User())
            ->setEmail('scheduled-autosave-'.$suffix.'@example.test')
            ->setDisplayName('Scheduled content editor')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::CONTENT])
            ->verifyEmail();
        $entry = (new ContentEntry())
            ->setAuthor($user)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Scheduled autosave '.$suffix)
            ->setSlug('scheduled-autosave-'.$suffix)
            ->setBody('Original scheduled body')
            ->setEditorDocument($originalDocument)
            ->setScheduledAt($scheduledAt)
            ->setScheduledUnpublishAt($scheduledUnpublishAt)
            ->setStatus(ContentEntry::STATUS_SCHEDULED);
        $entry->synchronizePublication();

        $entityManager->persist($user);
        $entityManager->persist($entry);
        $entityManager->flush();
        $originalUpdatedAt = $entry->getUpdatedAt();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/content/'.$entry->getId().'/edit');
        self::assertResponseIsSuccessful();
        $editor = $crawler->filter('[data-controller="content-editor"]');
        self::assertCount(1, $editor);
        $token = $editor->attr('data-content-editor-token-value');
        $updatedAt = $editor->attr('data-content-editor-updated-at-value');
        self::assertNotNull($token);
        self::assertNotNull($updatedAt);

        $submittedDocument = ContentBlockDocument::PREFIX.json_encode(
            [
                'version' => ContentBlockDocument::VERSION,
                'blocks' => [['type' => 'text', 'text' => 'Must not replace scheduled content']],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $client->request(
            'POST',
            '/admin/content/'.$entry->getId().'/editor/autosave',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(
                ['document' => $submittedDocument, 'updatedAt' => $updatedAt],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(409);

        $entityManager->clear();
        $reloaded = $entityManager->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $reloaded);
        self::assertSame(ContentEntry::STATUS_SCHEDULED, $reloaded->getStatus());
        self::assertSame('Original scheduled body', $reloaded->getBody());
        self::assertSame($originalDocument, $reloaded->getEditorDocument());
        self::assertEquals($scheduledAt, $reloaded->getScheduledAt());
        self::assertEquals($scheduledUnpublishAt, $reloaded->getScheduledUnpublishAt());
        self::assertNull($reloaded->getPublishedAt());
        self::assertEquals($originalUpdatedAt, $reloaded->getUpdatedAt());
        self::assertCount(0, $entityManager->getRepository(ContentRevision::class)->findBy(['entry' => $reloaded]));
    }
}
