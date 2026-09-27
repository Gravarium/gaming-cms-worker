<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Community\Interaction\ContentEntryTargetProvider;
use App\Entity\CmsModuleState;
use App\Entity\Community\CommunityComment;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class ContentDiscussionWidgetProviderTest extends WebTestCase
{
    public function testPageBuilderWidgetListsOnlyPublishedListedContentInStableOrder(): void
    {
        $client = static::createClient();
        $previousEnabled = $this->setContentEnabled($client, true);
        try {
            $author = $this->user($client);
            $pinned = $this->entry($client, $author, 'widget-pinned', new \DateTimeImmutable('-8 days'), pinned: true);
            $recentA = $this->entry($client, $author, 'widget-recent-a', new \DateTimeImmutable('-1 day'));
            $recentB = $this->entry($client, $author, 'widget-recent-b', new \DateTimeImmutable('-1 day'));
            $this->comment($client, $author, $pinned, 'Pinned discussion comment.');
            $this->comment($client, $author, $recentB, 'Recent discussion comment one.');
            $this->comment($client, $author, $recentB, 'Recent discussion comment two.');
            $hidden = $this->comment($client, $author, $pinned, 'Hidden comment excluded from the count.');
            $hidden->softDelete($author, 'Moderator-only reason.');
            $this->em($client)->flush();

            for ($i = 1; $i <= 12; ++$i) {
                $this->entry(
                    $client,
                    $author,
                    'widget-extra-'.$i,
                    new \DateTimeImmutable('-5 days'),
                );
            }
            $this->entry($client, $author, 'widget-unlisted', new \DateTimeImmutable('-1 hour'), unlisted: true);
            $this->entry(
                $client,
                $author,
                'widget-draft',
                null,
                status: ContentEntry::STATUS_DRAFT,
            );

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get('content.discussions');
            self::assertNotNull($definition);
            self::assertSame('content', $definition->module);
            self::assertTrue($registry->available('content.discussions'));

            $validator = $client->getContainer()->get(LayoutValidator::class);
            $layout = $validator->defaults('nebula')->toArray();
            $layout['widgets'][] = [
                'id' => 'content-discussions',
                'type' => 'content.discussions',
                'region' => 'hero',
                'enabled' => true,
                'config' => ['count' => 3],
            ];
            $validated = $validator->validate($layout)->toArray();
            self::assertSame('content.discussions', $validated['widgets'][1]['type']);
            self::assertSame(3, $validated['widgets'][1]['config']['count']);

            $data = $registry->data('content.discussions', ['count' => 3]);
            /** @var list<array{entry: ContentEntry, commentCount: int}> $items */
            $items = $data['items'] ?? [];
            self::assertCount(3, $items);
            self::assertSame(
                ['widget-pinned', 'widget-recent-b', 'widget-recent-a'],
                array_map(static fn (array $item): string => $item['entry']->getSlug(), $items),
            );
            self::assertSame([1, 2, 0], array_map(static fn (array $item): int => $item['commentCount'], $items));
            self::assertCount(12, $registry->data('content.discussions', ['count' => 50])['items']);
            self::assertCount(1, $registry->data('content.discussions', ['count' => 0])['items']);

            $rendered = $client->getContainer()->get(Environment::class)->render(
                'widget/content_discussions.html.twig',
                ['items' => $items],
            );
            self::assertStringContainsString('/content/widget-pinned/discussion', $rendered);
            self::assertStringContainsString('/content/widget-recent-b/discussion', $rendered);
            self::assertStringNotContainsString('widget-unlisted', $rendered);
            self::assertStringNotContainsString('widget-draft', $rendered);
        } finally {
            $this->restoreContentEnabled($client, $previousEnabled);
        }
    }

    public function testDisabledContentSuppressesTheWidgetAndPreventsNewPlacement(): void
    {
        $client = static::createClient();
        $registry = $client->getContainer()->get(WidgetRegistry::class);
        $validator = $client->getContainer()->get(LayoutValidator::class);
        $previousEnabled = $this->setContentEnabled($client, false);
        try {
            self::assertFalse($registry->available('content.discussions'));
            self::assertSame([], $registry->data('content.discussions', ['count' => 6]));

            $layout = $validator->defaults('nebula')->toArray();
            $layout['widgets'][] = [
                'id' => 'disabled-discussions',
                'type' => 'content.discussions',
                'region' => 'hero',
                'enabled' => true,
                'config' => ['count' => 6],
            ];
            try {
                $validator->validate($layout);
                self::fail('Disabled module widget unexpectedly passed Page Builder validation.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        } finally {
            $this->restoreContentEnabled($client, $previousEnabled);
        }
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('content-widget-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content widget author')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(
        KernelBrowser $client,
        User $author,
        string $slug,
        ?\DateTimeImmutable $publishedAt,
        bool $unlisted = false,
        bool $pinned = false,
        string $status = ContentEntry::STATUS_PUBLISHED,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($slug)
            ->setSlug($slug)
            ->setBody('Public widget fixture body.')
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setUnlisted($unlisted)
            ->setPinned($pinned);
        $this->em($client)->persist($entry);
        $this->em($client)->flush();

        return $entry;
    }

    private function comment(KernelBrowser $client, User $author, ContentEntry $entry, string $body): CommunityComment
    {
        $comment = new CommunityComment(ContentEntryTargetProvider::TYPE, (int) $entry->getId(), $author, $body);
        $this->em($client)->persist($comment);
        $this->em($client)->flush();

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
