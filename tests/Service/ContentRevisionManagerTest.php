<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\Service\ContentRevisionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentRevisionManagerTest extends KernelTestCase
{
    public function testCrossEntryRestoreRejectsBeforeCreatingTargetRevision(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $user = (new User())
            ->setEmail('revision-scope-'.$suffix.'@example.test')
            ->setDisplayName('Revision scope test')
            ->verifyEmail();
        $target = (new ContentEntry())
            ->setAuthor($user)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Target '.$suffix)
            ->setSlug('revision-target-'.$suffix)
            ->setBody('Target content must remain unchanged.');
        $source = (new ContentEntry())
            ->setAuthor($user)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Source '.$suffix)
            ->setSlug('revision-source-'.$suffix)
            ->setBody('Source revision content.');
        $entityManager->persist($user);
        $entityManager->persist($target);
        $entityManager->persist($source);
        $entityManager->flush();

        $revision = new ContentRevision($source, 1, $user);
        $entityManager->persist($revision);
        $entityManager->flush();

        $targetId = $target->getId();
        $sourceId = $source->getId();
        $revisionId = $revision->getId();
        self::assertNotNull($targetId);
        self::assertNotNull($sourceId);
        self::assertNotNull($revisionId);

        try {
            $container->get(ContentRevisionManager::class)->restore($target, $revision, $user);
            self::fail('A revision from another content entry must be rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('Revision belongs to another content entry.', $exception->getMessage());
        }

        // A later unit-of-work flush must not persist a snapshot from the rejected restore.
        $entityManager->flush();
        $entityManager->clear();

        $storedTarget = $entityManager->find(ContentEntry::class, $targetId);
        self::assertInstanceOf(ContentEntry::class, $storedTarget);
        self::assertSame('Target content must remain unchanged.', $storedTarget->getBody());
        self::assertCount(0, $entityManager->getRepository(ContentRevision::class)->findBy(['entry' => $storedTarget]));

        $storedRevision = $entityManager->find(ContentRevision::class, $revisionId);
        self::assertInstanceOf(ContentRevision::class, $storedRevision);
        self::assertSame($sourceId, $storedRevision->getEntry()->getId());
    }
}
