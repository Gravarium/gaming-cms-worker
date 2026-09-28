<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentDiscussionEntryNavigationTest extends WebTestCase
{
    public function testPublishedNewsAndPagesLinkToReadablePublicDiscussions(): void
    {
        $client = static::createClient();
        $author = $this->user($client);

        foreach ([
            [ContentEntry::TYPE_NEWS, 'news'],
            [ContentEntry::TYPE_PAGE, 'page'],
        ] as [$type, $routePrefix]) {
            $entry = $this->entry($client, $author, $type, ContentEntry::STATUS_PUBLISHED);
            $crawler = $client->request('GET', '/'.$routePrefix.'/'.$entry->getSlug());
            self::assertResponseIsSuccessful();

            $discussionPath = '/content/'.$entry->getSlug().'/discussion';
            $discussionLink = $crawler->filter(sprintf('a[href="%s"]', $discussionPath));
            self::assertCount(1, $discussionLink);
            self::assertSame('Diskussion lesen und mitreden', trim($discussionLink->text()));
            self::assertSame(
                'Diskussion zu '.$entry->getTitle().' lesen und mitreden',
                $discussionLink->attr('aria-label'),
            );

            $client->click($discussionLink->link());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $entry->getTitle());
        }
    }

    public function testDraftPreviewDoesNotExposePublicDiscussionLink(): void
    {
        $client = static::createClient();
        $author = $this->user($client);
        $draft = $this->entry($client, $author, ContentEntry::TYPE_PAGE, ContentEntry::STATUS_DRAFT);
        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/content/'.(int) $draft->getId().'/preview');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Vorschau:', (string) $client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter(sprintf(
            'a[href="/content/%s/discussion"]',
            $draft->getSlug(),
        )));
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions = []): User
    {
        $user = (new User())
            ->setEmail('discussion-navigation-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Discussion navigation member')
            ->setPermissions($permissions)
            ->verifyEmail();

        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entry(
        KernelBrowser $client,
        User $author,
        string $type,
        string $status,
    ): ContentEntry {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle('Discussion navigation '.$suffix)
            ->setSlug('discussion-navigation-'.$suffix)
            ->setBody('Published content for the discussion navigation test.')
            ->setStatus($status)
            ->setPublishedAt($status === ContentEntry::STATUS_PUBLISHED ? new \DateTimeImmutable('-2 days') : null);

        $this->entityManager($client)->persist($entry);
        $this->entityManager($client)->flush();

        return $entry;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
