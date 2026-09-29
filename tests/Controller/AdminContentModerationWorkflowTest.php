<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Community\Interaction\ContentEntryTargetProvider;
use App\Community\Interaction\ReportRecord;
use App\Entity\CmsModuleState;
use App\Entity\Community\CommunityComment;
use App\Entity\Community\CommunityModerationDecision;
use App\Entity\Community\CommunityReport;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminContentModerationWorkflowTest extends WebTestCase
{
    public function testManagerCanReviewHideAndRestoreWithRequiredReasonsAndAudit(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $author = $this->user($client);
        $reporter = $this->user($client);
        $entry = $this->entry($client, $author);
        $comment = new CommunityComment(ContentEntryTargetProvider::TYPE, (int) $entry->getId(), $author, 'Comment requiring moderation.');
        $report = new CommunityReport($comment, $reporter, 'spam', 'Moderator-only report detail.');
        $this->em($client)->persist($comment);
        $this->em($client)->persist($report);
        $this->em($client)->flush();
        $reportId = (int) $report->getId();
        $commentId = (int) $comment->getId();
        $client->loginUser($manager);

        $reviewPath = '/admin/community/moderation/reports/'.$reportId.'/review';
        $decisionPath = '/admin/community/moderation/reports/'.$reportId.'/decision';
        $crawler = $client->request('GET', '/admin/community/moderation');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Moderator-only report detail.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Comment requiring moderation.', (string) $client->getResponse()->getContent());

        $client->request('POST', $reviewPath, []);
        self::assertResponseStatusCodeSame(403);
        $stored = $this->report($client, $reportId);
        self::assertSame(ReportRecord::STATUS_OPEN, $stored->getStatus());

        $crawler = $client->request('GET', '/admin/community/moderation');
        $reviewToken = $crawler->filter('form[action="'.$reviewPath.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $reviewPath, ['_token' => $reviewToken]);
        self::assertTrue($client->getResponse()->isRedirect());
        $stored = $this->report($client, $reportId);
        self::assertSame(ReportRecord::STATUS_REVIEWING, $stored->getStatus());

        $client->request('POST', $decisionPath, [
            'content_moderation_decision' => ['action' => 'uphold', 'reason' => 'This request has no CSRF token.'],
        ]);
        self::assertTrue($client->getResponse()->isRedirect());
        $stored = $this->report($client, $reportId);
        self::assertSame(ReportRecord::STATUS_REVIEWING, $stored->getStatus());

        $crawler = $client->request('GET', '/admin/community/moderation');
        $decisionForm = $crawler->filter('form[action="'.$decisionPath.'"]')->form([
            'content_moderation_decision[action]' => 'uphold',
            'content_moderation_decision[reason]' => '',
        ]);
        $client->submit($decisionForm);
        self::assertTrue($client->getResponse()->isRedirect());
        $stored = $this->report($client, $reportId);
        self::assertSame(ReportRecord::STATUS_REVIEWING, $stored->getStatus());

        $crawler = $client->request('GET', '/admin/community/moderation');
        $decisionForm = $crawler->filter('form[action="'.$decisionPath.'"]')->form([
            'content_moderation_decision[action]' => 'uphold',
            'content_moderation_decision[reason]' => 'Spam confirmed after review.',
        ]);
        $client->submit($decisionForm);
        self::assertTrue($client->getResponse()->isRedirect());

        $stored = $this->report($client, $reportId);
        self::assertSame(ReportRecord::STATUS_RESOLVED, $stored->getStatus());
        self::assertSame('Spam confirmed after review.', $stored->getDecisionReason());
        $hidden = $this->comment($client, $commentId);
        self::assertTrue($hidden->isDeleted());

        $client->request('GET', '/content/'.$entry->getSlug().'/discussion');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Comment requiring moderation.', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/admin/community/moderation');
        self::assertStringContainsString('Comment requiring moderation.', (string) $client->getResponse()->getContent());
        $restorePath = '/admin/community/moderation/comments/'.$commentId.'/restore';
        $restoreForm = $crawler->filter('form[action="'.$restorePath.'"]')->form([
            'content_moderation_decision[action]' => 'restore',
            'content_moderation_decision[reason]' => 'The appeal confirmed the comment is acceptable.',
        ]);
        $client->submit($restoreForm);
        self::assertTrue($client->getResponse()->isRedirect());

        self::assertFalse($this->comment($client, $commentId)->isDeleted());
        $client->request('GET', '/content/'.$entry->getSlug().'/discussion');
        self::assertStringContainsString('Comment requiring moderation.', (string) $client->getResponse()->getContent());

        $decisions = $this->em($client)->getRepository(CommunityModerationDecision::class)->findBy([
            'targetKind' => ContentEntryTargetProvider::TYPE,
            'targetId' => $entry->getId(),
        ]);
        $actions = array_map(static fn (CommunityModerationDecision $decision): string => $decision->getAction(), $decisions);
        self::assertContains('hide', $actions);
        self::assertContains('restore', $actions);
    }

    public function testRejectedReportIsAuditedAndLeavesTheCommentVisible(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $author = $this->user($client);
        $reporter = $this->user($client);
        $entry = $this->entry($client, $author);
        $comment = new CommunityComment(ContentEntryTargetProvider::TYPE, (int) $entry->getId(), $author, 'Comment that remains visible.');
        $report = new CommunityReport($comment, $reporter, 'other', 'A report that is not upheld.');
        $this->em($client)->persist($comment);
        $this->em($client)->persist($report);
        $this->em($client)->flush();
        $reportId = (int) $report->getId();
        $client->loginUser($manager);

        $decisionPath = '/admin/community/moderation/reports/'.$reportId.'/decision';
        $crawler = $client->request('GET', '/admin/community/moderation');
        $form = $crawler->filter('form[action="'.$decisionPath.'"]')->form([
            'content_moderation_decision[action]' => 'reject',
            'content_moderation_decision[reason]' => 'The report did not show a policy violation.',
        ]);
        $client->submit($form);
        self::assertTrue($client->getResponse()->isRedirect());

        $stored = $this->report($client, $reportId);
        self::assertSame(ReportRecord::STATUS_REJECTED, $stored->getStatus());
        self::assertFalse($this->comment($client, (int) $comment->getId())->isDeleted());
        $dismissal = $this->em($client)->getRepository(CommunityModerationDecision::class)->findOneBy([
            'targetKind' => ContentEntryTargetProvider::TYPE,
            'targetId' => $entry->getId(),
            'action' => 'dismiss_report',
        ]);
        self::assertInstanceOf(CommunityModerationDecision::class, $dismissal);
        self::assertSame('The report did not show a policy violation.', $dismissal->getReason());
    }

    public function testModerationQueuesPaginateIndependentlyPastOneHundredItems(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $author = $this->user($client);
        $reporter = $this->user($client);
        $entry = $this->entry($client, $author);
        $em = $this->em($client);

        for ($index = 1; $index <= 101; ++$index) {
            $comment = new CommunityComment(
                ContentEntryTargetProvider::TYPE,
                (int) $entry->getId(),
                $author,
                sprintf('Bulk moderation comment %03d', $index),
            );
            $comment->softDelete($manager, 'Bulk pagination fixture');
            $em->persist($comment);
            $em->persist(new CommunityReport($comment, $reporter, 'spam', sprintf('Bulk report %03d', $index)));
        }
        $em->flush();
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/community/moderation');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '#open-reports article');
        self::assertSelectorCount(25, '#hidden-comments article');
        self::assertSelectorTextContains('nav[aria-label="Seiten offener Berichte"]', 'Seite 1 von 5');
        self::assertSelectorTextContains('nav[aria-label="Seiten offener Berichte"]', '101 Berichte');
        self::assertSelectorTextContains('nav[aria-label="Seiten ausgeblendeter Kommentare"]', 'Seite 1 von 5');
        self::assertSelectorTextContains('nav[aria-label="Seiten ausgeblendeter Kommentare"]', '101 Kommentare');

        $crawler = $client->request('GET', '/admin/community/moderation?reports_page=2&hidden_page=5');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '#open-reports article');
        self::assertSelectorCount(1, '#hidden-comments article');
        self::assertSelectorTextContains('nav[aria-label="Seiten offener Berichte"]', 'Seite 2 von 5');
        self::assertSelectorTextContains('nav[aria-label="Seiten ausgeblendeter Kommentare"]', 'Seite 5 von 5');

        $nextReportHref = (string) $crawler->filter('nav[aria-label="Seiten offener Berichte"] a[rel="next"]')->attr('href');
        parse_str((string) parse_url($nextReportHref, PHP_URL_QUERY), $nextReportQuery);
        self::assertSame('3', (string) ($nextReportQuery['reports_page'] ?? ''));
        self::assertSame('5', (string) ($nextReportQuery['hidden_page'] ?? ''));

        $previousHiddenHref = (string) $crawler->filter('nav[aria-label="Seiten ausgeblendeter Kommentare"] a[rel="prev"]')->attr('href');
        parse_str((string) parse_url($previousHiddenHref, PHP_URL_QUERY), $previousHiddenQuery);
        self::assertSame('2', (string) ($previousHiddenQuery['reports_page'] ?? ''));
        self::assertSame('4', (string) ($previousHiddenQuery['hidden_page'] ?? ''));

        $client->request('GET', '/admin/community/moderation?reports_page=999999&hidden_page=999999');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '#open-reports article');
        self::assertSelectorCount(1, '#hidden-comments article');
        self::assertSelectorTextContains('nav[aria-label="Seiten offener Berichte"]', 'Seite 5 von 5');
        self::assertSelectorTextContains('nav[aria-label="Seiten ausgeblendeter Kommentare"]', 'Seite 5 von 5');
        self::assertSelectorTextContains('#open-reports', 'Bulk moderation comment 001');
        self::assertSelectorTextContains('#hidden-comments', 'Bulk moderation comment 001');

        $client->request('GET', '/admin/community/moderation?reports_page[]=5&hidden_page=invalid');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '#open-reports article');
        self::assertSelectorCount(25, '#hidden-comments article');
        self::assertSelectorTextContains('nav[aria-label="Seiten offener Berichte"]', 'Seite 1 von 5');
        self::assertSelectorTextContains('nav[aria-label="Seiten ausgeblendeter Kommentare"]', 'Seite 1 von 5');
    }

    public function testModerationRequiresContentPermissionAndIsHiddenWhenContentIsDisabled(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $visitor = $this->user($client);
        $client->loginUser($visitor);
        $client->request('GET', '/admin/community/moderation');
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($manager);
        $previousEnabled = $this->setContentEnabled($client, false);
        try {
            $client->request('GET', '/admin/community/moderation');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreContentEnabled($client, $previousEnabled);
        }
    }

    private function user(KernelBrowser $client, array $permissions = []): User
    {
        $user = (new User())
            ->setEmail('content-moderation-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content moderator test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(KernelBrowser $client, User $author): ContentEntry
    {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Moderation entry '.$suffix)
            ->setSlug('moderation-'.$suffix)
            ->setBody('Published moderation fixture.')
            ->setStatus(ContentEntry::STATUS_PUBLISHED)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));
        $this->em($client)->persist($entry);
        $this->em($client)->flush();

        return $entry;
    }

    private function report(KernelBrowser $client, int $id): CommunityReport
    {
        $em = $this->em($client);
        $em->clear();
        $report = $em->find(CommunityReport::class, $id);
        self::assertInstanceOf(CommunityReport::class, $report);

        return $report;
    }

    private function comment(KernelBrowser $client, int $id): CommunityComment
    {
        $em = $this->em($client);
        $em->clear();
        $comment = $em->find(CommunityComment::class, $id);
        self::assertInstanceOf(CommunityComment::class, $comment);

        return $comment;
    }

    private function setContentEnabled(KernelBrowser $client, bool $enabled): ?bool
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'content');
        $previous = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('content');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previous;
    }

    private function restoreContentEnabled(KernelBrowser $client, ?bool $previous): void
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'content');
        if ($state === null) {
            return;
        }
        if ($previous === null) {
            $em->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $em->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
