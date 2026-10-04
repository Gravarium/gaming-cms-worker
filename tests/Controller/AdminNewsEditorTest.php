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
    public function testWorklistSearchStatusAndPaginationKeepEntriesReachable(): void
    {
        $client = static::createClient();
        [$user, $original] = $this->entry($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $prefix = 'Worklist '.bin2hex(random_bytes(5));
        $drafts = [];
        for ($i = 0; $i < 27; $i++) {
            $entry = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_NEWS)
                ->setTitle($prefix.' draft '.$i)->setSlug('worklist-'.bin2hex(random_bytes(8)))->setBody('Draft');
            $em->persist($entry);
            $drafts[] = $entry;
        }
        $review = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_NEWS)
            ->setStatus(ContentEntry::STATUS_REVIEW)->setTitle($prefix.' review')
            ->setSlug('worklist-'.bin2hex(random_bytes(8)))->setBody('Review');
        $hidden = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_PAGE)
            ->setTitle($prefix.' page')->setSlug('worklist-'.bin2hex(random_bytes(8)))->setBody('Page');
        $em->persist($review);
        $em->persist($hidden);
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/news-editor?q='.rawurlencode($prefix).'&status=draft');
        self::assertResponseIsSuccessful();
        self::assertCount(25, $crawler->filter('tbody tr'));
        self::assertCount(1, $crawler->filter('a:contains("Nächste Seite")'));
        self::assertStringNotContainsString($prefix.' review', $client->getResponse()->getContent() ?: '');
        self::assertStringContainsString('private', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
        $crawler = $client->request('GET', '/admin/news-editor?q='.rawurlencode($prefix).'&status=draft&page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertCount(0, $crawler->filter('a:contains("Nächste Seite")'));
        self::assertCount(1, $crawler->filter('a:contains("Vorherige Seite")'));

        $client->request('GET', '/admin/news-editor?q='.rawurlencode($prefix).'&status=review');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($prefix.' review', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString($prefix.' page', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString($prefix.' draft', $client->getResponse()->getContent() ?: '');
        self::assertNotNull($original->getId());
    }

    public function testScheduledNewsRemainsInEditorialWorklistWithBerlinTimeAndMetadataLink(): void
    {
        $client = static::createClient();
        [$user, $draft] = $this->entry($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $title = 'Geplante Redaktions-News '.bin2hex(random_bytes(5));
        $berlin = new \DateTimeZone('Europe/Berlin');
        $planned = new \DateTimeImmutable('+2 days', $berlin);
        $planned = $planned->setTime(14, 30);
        $entry = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($title)->setSlug('scheduled-news-'.bin2hex(random_bytes(8)))
            ->setBody('Geplant')->setScheduledAt($planned->setTimezone(new \DateTimeZone('UTC')))
            ->setStatus(ContentEntry::STATUS_SCHEDULED);
        $entry->synchronizePublication();
        $em->persist($entry);
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/news-editor?q='.rawurlencode($title));
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent() ?: '';
        self::assertStringContainsString($title, $html);
        self::assertStringContainsString('Geplant', $html);
        self::assertStringContainsString($planned->format('d.m.Y H:i').' (Europe/Berlin)', $html);
        self::assertStringContainsString('/admin/content/'.$entry->getId().'/edit', $html);
        self::assertStringNotContainsString('/admin/news-editor/'.$entry->getId().'"', $html);

        $client->request('GET', '/admin/news-editor?status=scheduled&q='.rawurlencode($title));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($title, $client->getResponse()->getContent() ?: '');
        $crawler = $client->request('GET', '/admin/news-editor?status=draft&q='.rawurlencode($title));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($title, $crawler->filter('tbody')->text());
        self::assertNotNull($draft->getId());
    }

    public function testWorklistRejectsUnboundedAndInvalidFilters(): void
    {
        $client = static::createClient();
        [$user] = $this->entry($client);
        $client->loginUser($user);
        foreach (['page=0', 'page=1001', 'page=2x', 'status=published', 'status[]=draft', 'q='.str_repeat('x', 81)] as $query) {
            $client->request('GET', '/admin/news-editor?'.$query);
            self::assertResponseStatusCodeSame(404);
        }
    }

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

    public function testDraftCanBeSavedAndSubmittedForReviewFromVisualEditor(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $hash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf); self::assertNotNull($updated); self::assertNotNull($hash);
        self::assertCount(1, $crawler->selectButton('Speichern und zur Freigabe einreichen'));

        $client->request('POST', '/admin/news-editor/'.$entry->getId().'/submit-review', [
            '_token' => $csrf, 'updatedAt' => $updated, 'documentHash' => $hash,
            'document' => $this->document('Bereit für das Review'),
        ]);
        self::assertResponseRedirects('/admin/news-editor/'.$entry->getId());
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $stored);
        self::assertSame(ContentEntry::STATUS_REVIEW, $stored->getStatus());
        self::assertSame('Bereit für das Review', $stored->getBody());
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $stored]));

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Speichern und zur Freigabe einreichen'));
        self::assertStringContainsString('In Prüfung', $client->getResponse()->getContent() ?: '');
    }

    public function testReviewSubmissionRejectsCsrfStaleStateAndInvalidDocumentWithoutMutation(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $hash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf); self::assertNotNull($updated); self::assertNotNull($hash);
        $endpoint = '/admin/news-editor/'.$entry->getId().'/submit-review';
        $valid = ['updatedAt' => $updated, 'documentHash' => $hash, 'document' => $this->document('Review')];

        $client->request('POST', $endpoint, ['_token' => 'wrong'] + $valid);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $endpoint, ['_token' => $csrf, 'updatedAt' => '2000-01-01T00:00:00+00:00', 'documentHash' => $hash, 'document' => $valid['document']]);
        self::assertResponseStatusCodeSame(409);
        $client->request('POST', $endpoint, ['_token' => $csrf, 'updatedAt' => $updated, 'documentHash' => $hash, 'document' => RichDocument::PREFIX.'<script>']);
        self::assertResponseStatusCodeSame(422);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $stored);
        self::assertSame(ContentEntry::STATUS_DRAFT, $stored->getStatus());
        self::assertSame('Legacy original', $stored->getBody());
        self::assertCount(0, $em->getRepository(ContentRevision::class)->findBy(['entry' => $stored]));
    }

    public function testReviewCanPublishSavedVisualContentWithRevision(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client, status: ContentEntry::STATUS_REVIEW);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Änderungen speichern und jetzt veröffentlichen'));
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $hash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf); self::assertNotNull($updated); self::assertNotNull($hash);

        $client->request('POST', '/admin/news-editor/'.$entry->getId().'/review/publish', [
            '_token' => $csrf, 'updatedAt' => $updated, 'documentHash' => $hash,
            'document' => $this->document('Freigegebener Artikel'),
        ]);
        self::assertResponseRedirects('/admin/content/'.$entry->getId().'/edit');
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $published = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $published);
        self::assertSame(ContentEntry::STATUS_PUBLISHED, $published->getStatus());
        self::assertSame('Freigegebener Artikel', $published->getBody());
        self::assertNotNull($published->getPublishedAt());
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $published]));
        $client->request('GET', '/admin/news-editor/'.$entry->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testReviewDecisionRejectsCsrfStaleAndInvalidContentThenReturnsToDraft(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client, status: ContentEntry::STATUS_REVIEW);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        self::assertCount(1, $crawler->selectButton('Änderungen speichern und zur Überarbeitung zurückgeben'));
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $hash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf); self::assertNotNull($updated); self::assertNotNull($hash);
        $endpoint = '/admin/news-editor/'.$entry->getId().'/review/publish';
        $valid = ['_token' => $csrf, 'updatedAt' => $updated, 'documentHash' => $hash, 'document' => $this->document('Entscheidung')];
        $client->request('POST', $endpoint, ['_token' => 'wrong'] + $valid);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $endpoint, ['updatedAt' => '2000-01-01T00:00:00+00:00'] + $valid);
        self::assertResponseStatusCodeSame(409);
        $client->request('POST', $endpoint, ['document' => RichDocument::PREFIX.'<script>'] + $valid);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $endpoint, ['document' => $this->document('')] + $valid);
        self::assertResponseStatusCodeSame(422);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $unchanged = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $unchanged);
        self::assertSame(ContentEntry::STATUS_REVIEW, $unchanged->getStatus());
        self::assertSame('Legacy original', $unchanged->getBody());
        self::assertCount(0, $em->getRepository(ContentRevision::class)->findBy(['entry' => $unchanged]));

        $client->request('POST', '/admin/news-editor/'.$entry->getId().'/review/return-draft', $valid);
        self::assertResponseRedirects('/admin/news-editor/'.$entry->getId());
        $em->clear();
        $draft = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $draft);
        self::assertSame(ContentEntry::STATUS_DRAFT, $draft->getStatus());
        self::assertSame('Entscheidung', $draft->getBody());
        self::assertNull($draft->getPublishedAt());
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $draft]));
        $client->request('POST', $endpoint, $valid);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReviewedNewsCanBeScheduledFromVisualEditor(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client, status: ContentEntry::STATUS_REVIEW);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        self::assertCount(1, $crawler->selectButton('Änderungen speichern und Veröffentlichung planen'));
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $updated = $crawler->filter('input[name="updatedAt"]')->attr('value');
        $hash = $crawler->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($csrf); self::assertNotNull($updated); self::assertNotNull($hash);
        $planned = (new \DateTimeImmutable('+2 days', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d\\TH:i');
        $endpoint = '/admin/news-editor/'.$entry->getId().'/review/schedule';
        $valid = [
            '_token' => $csrf, 'updatedAt' => $updated, 'documentHash' => $hash,
            'document' => $this->document('Geplanter Artikel'), 'scheduledAt' => $planned,
        ];
        $client->request('POST', $endpoint, ['_token' => 'wrong'] + $valid);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $endpoint, ['scheduledAt' => '2000-01-01T00:00'] + $valid);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $endpoint, ['scheduledAt' => '2026-13-01T11:30'] + $valid);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $endpoint, ['updatedAt' => '2000-01-01T00:00:00+00:00'] + $valid);
        self::assertResponseStatusCodeSame(409);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $unchanged = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $unchanged);
        self::assertSame(ContentEntry::STATUS_REVIEW, $unchanged->getStatus());
        self::assertNull($unchanged->getScheduledAt());

        $client->request('POST', $endpoint, $valid);
        self::assertResponseRedirects('/admin/content/'.$entry->getId().'/edit');
        $em->clear();
        $scheduled = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $scheduled);
        self::assertSame(ContentEntry::STATUS_SCHEDULED, $scheduled->getStatus());
        self::assertSame('Geplanter Artikel', $scheduled->getBody());
        self::assertSame($planned, $scheduled->getScheduledAt()?->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d\\TH:i'));
        self::assertNull($scheduled->getPublishedAt());
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $scheduled]));
        $client->request('GET', '/admin/news-editor/'.$entry->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testVisualRevisionHistoryPreviewsAndRestoresSafely(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $entry->setEditorDocument($this->document('Alte Fassung'))->setBody('Alte Fassung');
        $revision = new ContentRevision($entry, 1, $user);
        $em->persist($revision);
        $entry->setEditorDocument($this->document('Aktuelle Fassung'))->setBody('Aktuelle Fassung');
        $em->flush();
        $client->loginUser($user);

        $history = $client->request('GET', '/admin/news-editor/'.$entry->getId().'/history');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
        self::assertCount(1, $history->filter('a:contains("Ansehen")'));
        $client->request('GET', '/admin/news-editor/'.$entry->getId().'/revisions/'.$revision->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Alte Fassung', $client->getResponse()->getContent() ?: '');

        $token = $history->filter('input[name="_token"]')->attr('value');
        $updated = $history->filter('input[name="updatedAt"]')->attr('value');
        $hash = $history->filter('input[name="documentHash"]')->attr('value');
        self::assertNotNull($token); self::assertNotNull($updated); self::assertNotNull($hash);
        $endpoint = '/admin/news-editor/'.$entry->getId().'/revisions/'.$revision->getId().'/restore';
        $client->request('POST', $endpoint, ['_token' => 'wrong', 'updatedAt' => $updated, 'documentHash' => $hash]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $endpoint, ['_token' => $token, 'updatedAt' => '2000-01-01T00:00:00+00:00', 'documentHash' => $hash]);
        self::assertResponseStatusCodeSame(409);
        $client->request('POST', $endpoint, ['_token' => $token, 'updatedAt' => $updated, 'documentHash' => $hash]);
        self::assertResponseRedirects('/admin/news-editor/'.$entry->getId());
        $em->clear();
        $restored = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $restored);
        self::assertSame('Alte Fassung', $restored->getBody());
        self::assertSame(ContentEntry::STATUS_DRAFT, $restored->getStatus());
        self::assertCount(2, $em->getRepository(ContentRevision::class)->findBy(['entry' => $restored]));
    }

    public function testVisualRevisionRoutesHideForeignSnapshots(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        [, $other] = $this->entry($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $foreign = new ContentRevision($other, 1, $user);
        $em->persist($foreign); $em->flush();
        $client->loginUser($user);
        $client->request('GET', '/admin/news-editor/'.$entry->getId().'/revisions/'.$foreign->getId());
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/admin/news-editor/'.$entry->getId().'/revisions/'.$foreign->getId().'/restore');
        self::assertResponseStatusCodeSame(404);
    }

    public function testVisualEditorDuplicatesNewsAsSafeIndependentDraft(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $entry->setEditorDocument($this->document('Kopierter Inhalt'))->setBody('Kopierter Inhalt')
            ->setSubtitle('Untertitel')->setExcerpt('Kurztext')->setFeatured(true)->setPinned(true)
            ->setUnlisted(true)->setSeoTitle('SEO')->setSeoDescription('Beschreibung')
            ->setCanonicalUrl('https://example.test/original')->setNoIndex(false);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        $form = $crawler->selectButton('Als neuen Entwurf duplizieren')->form();
        $client->submit($form);
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#\A/admin/news-editor/\d+\z#', $location);
        $copyId = (int) basename($location);
        self::assertNotSame($entry->getId(), $copyId);
        $em->clear();
        $copy = $em->find(ContentEntry::class, $copyId);
        self::assertInstanceOf(ContentEntry::class, $copy);
        self::assertSame(ContentEntry::STATUS_DRAFT, $copy->getStatus());
        self::assertSame('Kopierter Inhalt', $copy->getBody());
        self::assertSame('Untertitel', $copy->getSubtitle());
        self::assertSame('Kurztext', $copy->getExcerpt());
        self::assertSame($user->getId(), $copy->getAuthor()?->getId());
        self::assertFalse($copy->isFeatured()); self::assertFalse($copy->isPinned());
        self::assertTrue($copy->isUnlisted()); self::assertTrue($copy->isNoIndex());
        self::assertNull($copy->getCanonicalUrl());
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $copy]));
    }

    public function testVisualEditorTrashIsCsrfProtectedConflictSafeAndReversible(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->entry($client);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        $form = $crawler->selectButton('In den Papierkorb')->form();
        $values = $form->getPhpValues();
        $endpoint = '/admin/news-editor/'.$entry->getId().'/trash';
        $client->request('POST', $endpoint, array_replace($values, ['_token' => 'wrong']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $endpoint, array_replace($values, ['updatedAt' => '2000-01-01T00:00:00+00:00']));
        self::assertResponseStatusCodeSame(409);
        $client->request('POST', $endpoint, $values);
        self::assertResponseRedirects('/admin/news-editor');
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $trashed = $em->find(ContentEntry::class, $entry->getId());
        self::assertInstanceOf(ContentEntry::class, $trashed);
        self::assertSame(ContentEntry::STATUS_TRASHED, $trashed->getStatus());
        self::assertNotNull($trashed->getTrashedAt());
        self::assertCount(1, $em->getRepository(ContentRevision::class)->findBy(['entry' => $trashed]));
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
