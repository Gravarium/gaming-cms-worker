<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Community\Interaction\ContentEntryTargetProvider;
use App\Entity\CmsModuleState;
use App\Entity\Community\CommunityComment;
use App\Entity\Community\CommunityReaction;
use App\Entity\Community\CommunityReport;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ContentDiscussionWorkflowTest extends WebTestCase
{
    public function testPublishedAndUnlistedThreadsAreReadableButDraftAndHiddenCommentsAreNot(): void
    {
        $client = static::createClient();
        $author = $this->user($client);
        $published = $this->entry($client, $author, ContentEntry::STATUS_PUBLISHED);
        $unlisted = $this->entry($client, $author, ContentEntry::STATUS_PUBLISHED, true, ContentEntry::TYPE_PAGE);
        $draft = $this->entry($client, $author, ContentEntry::STATUS_DRAFT);

        $visible = new CommunityComment(ContentEntryTargetProvider::TYPE, (int) $published->getId(), $author, 'Visible discussion message.');
        $hidden = new CommunityComment(ContentEntryTargetProvider::TYPE, (int) $published->getId(), $author, 'Hidden moderator-only message.');
        $hidden->softDelete($author, 'Moderator-only explanation.');
        $this->em($client)->persist($visible);
        $this->em($client)->persist($hidden);
        $this->em($client)->flush();

        $client->request('GET', '/content/'.$published->getSlug().'/discussion');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Visible discussion message.', $html);
        self::assertStringNotContainsString('Hidden moderator-only message.', $html);
        self::assertStringNotContainsString('Moderator-only explanation.', $html);

        $client->request('GET', '/content/'.$unlisted->getSlug().'/discussion');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/content/'.$draft->getSlug().'/discussion');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSignedInMemberCanReplyReactAndReportButCannotUseAnotherContentsComment(): void
    {
        $client = static::createClient();
        $author = $this->user($client);
        $entry = $this->entry($client, $author, ContentEntry::STATUS_PUBLISHED);
        $otherEntry = $this->entry($client, $author, ContentEntry::STATUS_PUBLISHED);
        $parent = new CommunityComment(ContentEntryTargetProvider::TYPE, (int) $entry->getId(), $author, 'Parent comment.');
        $foreign = new CommunityComment(ContentEntryTargetProvider::TYPE, (int) $otherEntry->getId(), $author, 'Comment from a different content item.');
        $this->em($client)->persist($parent);
        $this->em($client)->persist($foreign);
        $this->em($client)->flush();
        $parentId = (int) $parent->getId();
        $foreignId = (int) $foreign->getId();

        $member = $this->user($client);
        $client->loginUser($member);
        $threadPath = '/content/'.$entry->getSlug().'/discussion';

        $crawler = $client->request('GET', $threadPath.'?reply_to='.$parentId);
        self::assertResponseIsSuccessful();
        self::assertSame((string) $parentId, $crawler->filter('input[name="public_content_comment[parentId]"]')->attr('value'));

        $form = $crawler->selectButton('Kommentar veröffentlichen')->form([
            'public_content_comment[body]' => 'A helpful reply.',
        ]);
        $client->submit($form);
        self::assertTrue($client->getResponse()->isRedirect());
        $reply = $this->em($client)->getRepository(CommunityComment::class)->findOneBy([
            'targetType' => ContentEntryTargetProvider::TYPE,
            'targetId' => $entry->getId(),
            'body' => 'A helpful reply.',
        ]);
        self::assertInstanceOf(CommunityComment::class, $reply);
        self::assertSame($parentId, $reply->getParent()?->getId());

        $crawler = $client->request('GET', $threadPath);
        $form = $crawler->selectButton('Kommentar veröffentlichen')->form([
            'public_content_comment[parentId]' => (string) $foreignId,
            'public_content_comment[body]' => 'This cross-content reply must fail.',
        ]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em($client)->getRepository(CommunityComment::class)->findOneBy([
            'targetType' => ContentEntryTargetProvider::TYPE,
            'targetId' => $entry->getId(),
            'body' => 'This cross-content reply must fail.',
        ]));

        $reactionPath = '/content/'.$entry->getSlug().'/discussion/comment/'.$parentId.'/reaction';
        $crawler = $client->request('GET', $threadPath);
        $reactionToken = $crawler->filter('form[action="'.$reactionPath.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $reactionPath, ['_token' => $reactionToken, 'reaction' => 'like']);
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(1, $this->em($client)->getRepository(CommunityReaction::class)->count([
            'comment' => $parent,
            'user' => $member,
            'reaction' => 'like',
        ]));

        $crawler = $client->request('GET', $threadPath);
        $reactionToken = $crawler->filter('form[action="'.$reactionPath.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $reactionPath, ['_token' => $reactionToken, 'reaction' => 'like']);
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(0, $this->em($client)->getRepository(CommunityReaction::class)->count([
            'comment' => $parent,
            'user' => $member,
            'reaction' => 'like',
        ]));

        $reportPath = '/content/'.$entry->getSlug().'/discussion/comment/'.$parentId.'/report';
        $crawler = $client->request('GET', $threadPath);
        $reportForm = $crawler->filter('form[action="'.$reportPath.'"]')->form([
            'public_content_report[reason]' => 'spam',
            'public_content_report[details]' => 'Repeated unsolicited links.',
        ]);
        $client->submit($reportForm);
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(1, $this->em($client)->getRepository(CommunityReport::class)->count([
            'comment' => $parent,
            'reporter' => $member,
        ]));

        $crawler = $client->request('GET', $threadPath);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Repeated unsolicited links.', (string) $client->getResponse()->getContent());

        $foreignReactionPath = '/content/'.$entry->getSlug().'/discussion/comment/'.$foreignId.'/reaction';
        $client->request('POST', $foreignReactionPath, ['reaction' => 'like']);
        self::assertResponseStatusCodeSame(404);

        $foreignReportPath = '/content/'.$entry->getSlug().'/discussion/comment/'.$foreignId.'/report';
        $client->request('POST', $foreignReportPath, []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousMutationIsDeniedAndDisabledContentHidesDiscussion(): void
    {
        $client = static::createClient();
        $author = $this->user($client);
        $entry = $this->entry($client, $author, ContentEntry::STATUS_PUBLISHED);

        $client->request('POST', '/content/'.$entry->getSlug().'/discussion/comment', []);
        self::assertResponseRedirects('/login');

        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($manager);
        $previousEnabled = $this->setContentEnabled($client, false);
        try {
            $client->request('GET', '/content/'.$entry->getSlug().'/discussion');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreContentEnabled($client, $previousEnabled);
        }
    }

    private function user(KernelBrowser $client, array $permissions = []): User
    {
        $user = (new User())
            ->setEmail('content-discussion-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Discussion member')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(
        KernelBrowser $client,
        User $author,
        string $status,
        bool $unlisted = false,
        string $type = ContentEntry::TYPE_NEWS,
    ): ContentEntry {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle('Discussion entry '.$suffix)
            ->setSlug('discussion-'.$suffix)
            ->setBody('Published content body.')
            ->setStatus($status)
            ->setPublishedAt($status === ContentEntry::STATUS_PUBLISHED ? new \DateTimeImmutable('-2 days') : null)
            ->setUnlisted($unlisted);
        $this->em($client)->persist($entry);
        $this->em($client)->flush();

        return $entry;
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
