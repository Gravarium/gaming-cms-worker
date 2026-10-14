<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportContentRedirectsCommand;
use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportContentRedirectsCommandTest extends KernelTestCase
{
    public function testDryRunApplyAndRepeatAreSafeAndIdempotent(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $user = (new User())->setEmail('redirect-import-'.$suffix.'@example.test')
            ->setDisplayName('Redirect import')->setPermissions([CmsPermission::ACCESS, CmsPermission::CONTENT])->verifyEmail();
        $targetSlug = 'target-'.$suffix;
        $sourceSlug = 'legacy-'.$suffix;
        $target = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Imported target')->setSlug($targetSlug)->setBody('Body remains unchanged')
            ->setStatus(ContentEntry::STATUS_PUBLISHED)->synchronizePublication();
        $em->persist($user);
        $em->persist($target);
        $em->flush();

        $path = tempnam(sys_get_temp_dir(), 'redirect-import-');
        self::assertNotFalse($path);
        file_put_contents($path, sprintf(
            "Quellpfad,Inhaltstyp,Zieltitel,Zielslug,Status\n/news/%s,news,Imported target,%s,published\n",
            $sourceSlug,
            $targetSlug,
        ));

        try {
            $command = $container->get(ImportContentRedirectsCommand::class);
            $dryRun = new CommandTester($command);
            self::assertSame(0, $dryRun->execute(['file' => $path]));
            self::assertStringContainsString('Dry-Run', $dryRun->getDisplay());
            self::assertNull($em->getRepository(ContentRedirect::class)->findOneBy(['sourceSlug' => $sourceSlug]));

            $apply = new CommandTester($command);
            self::assertSame(0, $apply->execute(['file' => $path, '--apply' => true]));
            self::assertNotNull($em->getRepository(ContentRedirect::class)->findOneBy(['sourceSlug' => $sourceSlug]));

            $repeat = new CommandTester($command);
            self::assertSame(0, $repeat->execute(['file' => $path, '--apply' => true]));
            self::assertStringContainsString('1 unverändert', $repeat->getDisplay());
        } finally {
            $redirect = $em->getRepository(ContentRedirect::class)->findOneBy(['sourceSlug' => $sourceSlug]);
            if ($redirect instanceof ContentRedirect) {
                $em->remove($redirect);
            }
            $em->remove($target);
            $em->remove($user);
            $em->flush();
            unlink($path);
        }
    }
}
