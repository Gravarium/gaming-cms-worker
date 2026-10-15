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

final class PublicContentReleaseAtomFeedControllerTest extends WebTestCase
{
    public function testFeedContainsOnlyPublicReleaseMetadataWithAtomHeaders(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em($client);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $author = (new User())
                ->setEmail('release-atom-'.$suffix.'@example.test')
                ->setDisplayName('Private Atom author')
                ->verifyEmail();
            $entry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle('Private entry '.$suffix)
                ->setSlug('private-release-entry-'.$suffix)
                ->setExcerpt('Private entry excerpt')
                ->setBody('Private entry body must not be exported')
                ->setStatus(ContentEntry::STATUS_DRAFT);
            $publishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $release = (new ContentRelease())
                ->setName('Latest & <Games> '.$suffix)
                ->setDescription('Summary "quoted" & <safe> '.$suffix)
                ->setCreatedBy($author)
                ->addEntry($entry);
            $release->publish($publishedAt);
            $draft = (new ContentRelease())
                ->setName('Private draft '.$suffix)
                ->setDescription('Never exposed')
                ->setCreatedBy($author);
            $futureEntry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle('Future entry '.$suffix)
                ->setSlug('future-release-entry-'.$suffix)
                ->setExcerpt('Future excerpt')
                ->setBody('Future body')
                ->setStatus(ContentEntry::STATUS_DRAFT);
            $future = (new ContentRelease())
                ->setName('Future release '.$suffix)
                ->setCreatedBy($author)
                ->addEntry($futureEntry);
            $future->publish(new \DateTimeImmutable('+1 day'));

            foreach ([$author, $entry, $release, $draft, $futureEntry, $future] as $record) {
                $em->persist($record);
            }
            $em->flush();

            $client->request('GET', '/releases.atom');

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/atom+xml; charset=UTF-8');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertResponseHeaderSame('Last-Modified', $publishedAt->format('D, d M Y H:i:s').' GMT');

            $xml = new \DOMDocument();
            self::assertTrue($xml->loadXML((string) $client->getResponse()->getContent()));
            $xpath = new \DOMXPath($xml);
            $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
            self::assertSame(1, $xpath->query('/atom:feed')->length);
            self::assertGreaterThanOrEqual(1, $xpath->query('/atom:feed/atom:entry')->length);
            self::assertSame('Latest & <Games> '.$suffix, $xpath->evaluate('string(/atom:feed/atom:entry[1]/atom:title)'));
            self::assertSame('Summary "quoted" & <safe> '.$suffix, $xpath->evaluate('string(/atom:feed/atom:entry[1]/atom:summary)'));
            self::assertStringContainsString('/releases/'.$release->getId(), (string) $xpath->evaluate('string(/atom:feed/atom:entry[1]/atom:id)'));

            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('Private draft '.$suffix, $body);
            self::assertStringNotContainsString('Future release '.$suffix, $body);
            self::assertStringNotContainsString('Private Atom author', $body);
            self::assertStringNotContainsString('release-atom-'.$suffix.'@example.test', $body);
            self::assertStringNotContainsString('Private entry body', $body);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $em->clear();
        }
    }

    public function testDisabledContentModuleHidesAtomFeed(): void
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
            $client->request('GET', '/releases.atom');
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
