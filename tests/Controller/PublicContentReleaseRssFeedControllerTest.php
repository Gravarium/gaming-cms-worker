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

final class PublicContentReleaseRssFeedControllerTest extends WebTestCase
{
    public function testFeedIsHiddenWhenTheContentModuleIsDisabled(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $existingState = $entityManager->find(CmsModuleState::class, 'content');
        $previousEnabled = $existingState?->isEnabled();
        $state = $existingState ?? (new CmsModuleState())->setModuleKey('content')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/releases.rss');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $entityManager = $this->entityManager($client);
            $currentState = $entityManager->find(CmsModuleState::class, 'content');
            if ($previousEnabled === null) {
                if ($currentState instanceof CmsModuleState) {
                    $entityManager->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                $currentState->setEnabled($previousEnabled);
            }
            $entityManager->flush();
        }
    }

    public function testFeedExposesOnlyAlreadyPublishedReleasesAndUsesSafeHeaders(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($entityManager, $suffix);
            $public = $this->release($entityManager, $user, 'Public <release> & notes '.$suffix, 'Public & escaped summary', new \DateTimeImmutable('-1 hour'));
            $this->release($entityManager, $user, 'Future release '.$suffix, 'Must stay hidden', new \DateTimeImmutable('+1 day'));
            $draft = (new ContentRelease())
                ->setName('Draft release '.$suffix)
                ->setDescription('Must stay hidden')
                ->setCreatedBy($user);
            $entityManager->persist($draft);
            $entityManager->flush();

            $client->request('GET', '/releases.rss');

            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertSame('application/rss+xml; charset=UTF-8', $response->headers->get('Content-Type'));
            self::assertStringContainsString('no-store', strtolower((string) $response->headers->get('Cache-Control')));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

            $xml = $response->getContent() ?: '';
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($xml));
            $xpath = new \DOMXPath($document);
            $items = $xpath->query('/rss/channel/item');
            self::assertNotFalse($items);
            self::assertGreaterThanOrEqual(1, $items->length);
            $publicGuid = $xpath->query('/rss/channel/item/guid[text()="content-release-'.$public->getId().'@gaming-cms"]');
            self::assertNotFalse($publicGuid);
            self::assertSame(1, $publicGuid->length);
            self::assertStringContainsString('Public &lt;release&gt; &amp; notes '.$suffix, $xml);
            self::assertStringContainsString('Public &amp; escaped summary', $xml);
            self::assertStringContainsString('/releases/'.$public->getId(), $xml);
            self::assertStringNotContainsString('Future release '.$suffix, $xml);
            self::assertStringNotContainsString('Draft release '.$suffix, $xml);
            self::assertStringNotContainsString('Must stay hidden', $xml);
            self::assertStringNotContainsString('public-release-'.$suffix.'@example.test', $xml);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function release(EntityManagerInterface $entityManager, User $user, string $name, string $description, \DateTimeImmutable $publishedAt): ContentRelease
    {
        $entry = (new ContentEntry())
            ->setAuthor($user)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($name)
            ->setSlug('release-entry-'.bin2hex(random_bytes(6)))
            ->setExcerpt($description)
            ->setBody('Public release test body.')
            ->setStatus(ContentEntry::STATUS_DRAFT);
        $release = (new ContentRelease())
            ->setName($name)
            ->setDescription($description)
            ->setCreatedBy($user)
            ->addEntry($entry);
        $release->publish($publishedAt);
        $entityManager->persist($entry);
        $entityManager->persist($release);

        return $release;
    }

    private function user(EntityManagerInterface $entityManager, string $suffix): User
    {
        $user = (new User())
            ->setEmail('public-release-'.$suffix.'@example.test')
            ->setDisplayName('Public release test');
        $entityManager->persist($user);

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
