<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentReleaseJsonFeedControllerTest extends WebTestCase
{
    public function testFeedExportsOnlyPublicReleaseMetadataWithJsonFeedHeaders(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em($client);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $author = (new User())
                ->setEmail('release-json-feed-'.$suffix.'@example.test')
                ->setDisplayName('Private JSON Feed author')
                ->verifyEmail();
            $entry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle('Private release content '.$suffix)
                ->setSlug('private-json-release-entry-'.$suffix)
                ->setExcerpt('Private excerpt')
                ->setBody('Private entry body must not be exported')
                ->setStatus(ContentEntry::STATUS_DRAFT);
            $publishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $release = (new ContentRelease())
                ->setName('Public JSON release '.$suffix)
                ->setDescription('Public summary & details '.$suffix)
                ->setCreatedBy($author)
                ->addEntry($entry);
            $release->publish($publishedAt);
            $draft = (new ContentRelease())
                ->setName('Private JSON draft '.$suffix)
                ->setDescription('Never exported')
                ->setCreatedBy($author);
            $futureEntry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle('Future release content '.$suffix)
                ->setSlug('future-json-release-entry-'.$suffix)
                ->setExcerpt('Future excerpt')
                ->setBody('Future body')
                ->setStatus(ContentEntry::STATUS_DRAFT);
            $future = (new ContentRelease())
                ->setName('Future JSON release '.$suffix)
                ->setCreatedBy($author)
                ->addEntry($futureEntry);
            $future->publish(new \DateTimeImmutable('+1 day'));

            foreach ([$author, $entry, $release, $draft, $futureEntry, $future] as $record) {
                $em->persist($record);
            }
            $em->flush();

            $client->request('GET', '/releases.jsonfeed');

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/feed+json; charset=UTF-8');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $feed = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
            self::assertStringContainsString('/releases.jsonfeed', $feed['feed_url']);
            self::assertStringContainsString('/releases', $feed['home_page_url']);
            $item = null;
            foreach ($feed['items'] as $candidate) {
                if ($candidate['title'] === 'Public JSON release '.$suffix) {
                    $item = $candidate;
                    break;
                }
            }
            self::assertIsArray($item);
            self::assertSame('Public summary & details '.$suffix, $item['content_text']);
            self::assertStringContainsString('/releases/'.$release->getId(), $item['id']);
            self::assertArrayNotHasKey('authors', $item);

            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('Private JSON draft '.$suffix, $body);
            self::assertStringNotContainsString('Future JSON release '.$suffix, $body);
            self::assertStringNotContainsString('release-json-feed-'.$suffix.'@example.test', $body);
            self::assertStringNotContainsString('Private entry body', $body);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $em->clear();
        }
    }

    public function testDisabledContentModuleHidesJsonFeed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em($client);
        $existingState = $em->find(CmsModuleState::class, 'content');
        $previousEnabled = $existingState?->isEnabled();
        $state = $existingState ?? (new CmsModuleState())->setModuleKey('content')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        try {
            $client->request('GET', '/releases.jsonfeed');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $this->em($client);
            $currentState = $em->find(CmsModuleState::class, 'content');
            if ($previousEnabled === null) {
                if ($currentState instanceof CmsModuleState) {
                    $em->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                $currentState->setEnabled($previousEnabled);
            }
            $em->flush();
        }
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
