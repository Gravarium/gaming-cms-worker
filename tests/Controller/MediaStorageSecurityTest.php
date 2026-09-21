<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ExternalConnectorTarget;
use App\Entity\MediaAsset;
use App\Entity\MediaAssetReplica;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MediaStorageSecurityTest extends WebTestCase
{
    public function testStorageAndVideoRequireTheirOwnPermissions(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($user);

        $client->request('GET', '/admin/storage');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/videos');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDirectMissingMediaObjectReturnsNotFoundForAuthorizedUser(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('GET', '/admin/storage/media/999999999/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMediaDeleteRejectsMissingCsrfBeforeStorageMutation(): void
    {
        $client = static::createClient();
        $asset = $this->asset($client, 'csrf.txt');
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('POST', '/admin/storage/media/'.$assetId.'/delete');
        self::assertResponseStatusCodeSame(403);

        self::assertInstanceOf(MediaAsset::class, $this->em($client)->find(MediaAsset::class, $assetId));
    }

    public function testBulkMoveRejectsManipulatedMissingFolderInsteadOfMovingToRoot(): void
    {
        $client = static::createClient();
        $folder = (new MediaFolder())->setName('Original')->setSlug('original-'.bin2hex(random_bytes(4)));
        $this->em($client)->persist($folder);
        $asset = $this->asset($client, 'move.txt')->setFolder($folder);
        $this->em($client)->flush();
        $assetId = $asset->getId();
        $folderId = $folder->getId();
        self::assertNotNull($assetId);
        self::assertNotNull($folderId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('POST', '/admin/storage/media/bulk', [
            '_token' => $this->csrf($client, 'bulk-media'),
            'bulk_action' => 'move',
            'target_folder' => '999999999',
            'assets' => [(string) $assetId],
        ]);

        self::assertResponseRedirects('/admin/storage');
        $this->em($client)->clear();
        $stored = $this->em($client)->find(MediaAsset::class, $assetId);
        self::assertInstanceOf(MediaAsset::class, $stored);
        self::assertSame($folderId, $stored->getFolder()?->getId());
    }

    public function testBulkMoveAllowsExistingFolderAsPositivePath(): void
    {
        $client = static::createClient();
        $source = (new MediaFolder())->setName('Source')->setSlug('source-'.bin2hex(random_bytes(4)));
        $target = (new MediaFolder())->setName('Target')->setSlug('target-'.bin2hex(random_bytes(4)));
        $this->em($client)->persist($source);
        $this->em($client)->persist($target);
        $asset = $this->asset($client, 'valid-move.txt')->setFolder($source);
        $this->em($client)->flush();
        $assetId = $asset->getId();
        $targetId = $target->getId();
        self::assertNotNull($assetId);
        self::assertNotNull($targetId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('POST', '/admin/storage/media/bulk', [
            '_token' => $this->csrf($client, 'bulk-media'),
            'bulk_action' => 'move',
            'target_folder' => (string) $targetId,
            'assets' => [(string) $assetId],
        ]);

        self::assertResponseRedirects('/admin/storage');
        $this->em($client)->clear();
        $stored = $this->em($client)->find(MediaAsset::class, $assetId);
        self::assertInstanceOf(MediaAsset::class, $stored);
        self::assertSame($targetId, $stored->getFolder()?->getId());
    }

    public function testFolderEditRejectsDescendantAsParent(): void
    {
        $client = static::createClient();
        $root = (new MediaFolder())->setName('Root')->setSlug('root-'.bin2hex(random_bytes(4)));
        $child = (new MediaFolder())->setName('Child')->setSlug('child-'.bin2hex(random_bytes(4)))->setParent($root);
        $this->em($client)->persist($root);
        $this->em($client)->persist($child);
        $this->em($client)->flush();
        $rootId = $root->getId();
        $childId = $child->getId();
        self::assertNotNull($rootId);
        self::assertNotNull($childId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $crawler = $client->request('GET', '/admin/storage/folders/'.$rootId.'/edit');
        $form = $crawler->selectButton('Ordner speichern')->form([
            'media_folder[parent]' => (string) $childId,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Unterordner');

        $this->em($client)->clear();
        $storedRoot = $this->em($client)->find(MediaFolder::class, $rootId);
        self::assertInstanceOf(MediaFolder::class, $storedRoot);
        self::assertNull($storedRoot->getParent());
    }

    public function testFolderFormRejectsUnexpectedFieldsWithoutPersistingThem(): void
    {
        $client = static::createClient();
        $folder = (new MediaFolder())->setName('Stable')->setSlug('stable-'.bin2hex(random_bytes(4)));
        $this->em($client)->persist($folder);
        $this->em($client)->flush();
        $folderId = $folder->getId();
        self::assertNotNull($folderId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $crawler = $client->request('GET', '/admin/storage/folders/'.$folderId.'/edit');
        $form = $crawler->selectButton('Ordner speichern')->form();
        $values = $form->getPhpValues();
        $values['media_folder']['name'] = 'Manipulated';
        $values['media_folder']['storageMode'] = 'external';
        $client->request('POST', '/admin/storage/folders/'.$folderId.'/edit', $values);

        self::assertResponseStatusCodeSame(422);
        $this->em($client)->clear();
        $stored = $this->em($client)->find(MediaFolder::class, $folderId);
        self::assertInstanceOf(MediaFolder::class, $stored);
        self::assertSame('Stable', $stored->getName());
    }

    public function testNonEmptyFolderCannotBeDeletedAndEmptyFolderCan(): void
    {
        $client = static::createClient();
        $parent = (new MediaFolder())->setName('Parent')->setSlug('parent-'.bin2hex(random_bytes(4)));
        $child = (new MediaFolder())->setName('Child')->setSlug('child-'.bin2hex(random_bytes(4)))->setParent($parent);
        $empty = (new MediaFolder())->setName('Empty')->setSlug('empty-'.bin2hex(random_bytes(4)));
        foreach ([$parent, $child, $empty] as $folder) {
            $this->em($client)->persist($folder);
        }
        $this->em($client)->flush();
        $parentId = $parent->getId();
        $emptyId = $empty->getId();
        self::assertNotNull($parentId);
        self::assertNotNull($emptyId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('POST', '/admin/storage/folders/'.$parentId.'/delete', [
            '_token' => $this->csrf($client, 'delete-media-folder-'.$parentId),
        ]);
        self::assertResponseRedirects('/admin/storage');
        self::assertInstanceOf(MediaFolder::class, $this->em($client)->find(MediaFolder::class, $parentId));

        $client->request('POST', '/admin/storage/folders/'.$emptyId.'/delete', [
            '_token' => $this->csrf($client, 'delete-media-folder-'.$emptyId),
        ]);
        self::assertResponseRedirects('/admin/storage');
        $this->em($client)->clear();
        self::assertNull($this->em($client)->find(MediaFolder::class, $emptyId));
    }

    public function testInternalUploadIgnoresGlobalExternalConnectorTargets(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $targetKey = 'media-test-'.$suffix;
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MEDIA)
            ->setTargetKey($targetKey)
            ->setProviderKey('s3-compatible')
            ->setDisplayName('Should not be used for internal upload')
            ->setConfigurationReference('media.'.$suffix)
            ->setRequired(true)
            ->setEnabled(true);
        $this->em($client)->persist($target);
        $this->em($client)->flush();

        $path = sys_get_temp_dir().'/internal-media-'.$suffix.'.txt';
        file_put_contents($path, 'safe internal upload');
        $originalName = basename($path);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        try {
            $crawler = $client->request('GET', '/admin/storage/media/upload');
            $form = $crawler->selectButton('Datei hochladen')->form();
            $form['media_asset_upload[moduleKey]']->select('content');
            $form['media_asset_upload[file]']->upload($path);
            $client->submit($form);

            self::assertResponseRedirects('/admin/storage');
            $asset = $this->em($client)->getRepository(MediaAsset::class)->findOneBy(['originalName' => $originalName]);
            self::assertInstanceOf(MediaAsset::class, $asset);
            self::assertFalse($asset->isExternal());
            self::assertStringStartsWith('/uploads/media/content/', $asset->getLocation());
        } finally {
            $em = $this->em($client);
            $em->clear();
            $asset = $em->getRepository(MediaAsset::class)->findOneBy(['originalName' => $originalName]);
            if ($asset instanceof MediaAsset) {
                $storedPath = $client->getContainer()->getParameter('kernel.project_dir').'/public'.$asset->getLocation();
                if (is_file($storedPath)) {
                    @unlink($storedPath);
                }
                $em->remove($asset);
            }
            $storedTarget = $em->getRepository(ExternalConnectorTarget::class)->findOneBy(['targetKey' => $targetKey]);
            if ($storedTarget instanceof ExternalConnectorTarget) {
                $em->remove($storedTarget);
            }
            $em->flush();
            @unlink($path);
        }
    }

    public function testPendingDeletionCannotBeEditedReplacedOrDeletedAgain(): void
    {
        $client = static::createClient();
        $asset = $this->asset($client, 'pending.txt')->markDeletionPending();
        $this->em($client)->flush();
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('GET', '/admin/storage/media/'.$assetId.'/edit');
        self::assertResponseStatusCodeSame(409);

        $client->request('GET', '/admin/storage/media/'.$assetId.'/replace');
        self::assertResponseStatusCodeSame(409);

        $client->request('POST', '/admin/storage/media/'.$assetId.'/delete');
        self::assertResponseStatusCodeSame(409);
    }

    public function testExternalDeleteFailureLeavesRepairablePendingIntent(): void
    {
        $client = static::createClient();
        $asset = (new MediaAsset())
            ->setModuleKey('video')
            ->setStorageMode('external')
            ->setLocation('https://media.example.test/video.mp4')
            ->setOriginalName('video.mp4')
            ->setTitle('Video')
            ->setMimeType('video/mp4')
            ->setFileSize(123);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('POST', '/admin/storage/media/'.$assetId.'/delete', [
            '_token' => $this->csrf($client, 'delete-media-'.$assetId),
        ]);

        self::assertResponseRedirects('/admin/storage');
        $this->em($client)->clear();
        $stored = $this->em($client)->find(MediaAsset::class, $assetId);
        self::assertInstanceOf(MediaAsset::class, $stored);
        self::assertTrue($stored->isDeletionPending());
        self::assertNotNull($stored->getDeletionRequestedAt());
    }

    public function testUnknownReplicaTargetLeavesRepairablePendingIntent(): void
    {
        $client = static::createClient();
        $asset = (new MediaAsset())
            ->setModuleKey('video')
            ->setStorageMode('external')
            ->setLocation('https://media.example.test/video.mp4')
            ->setOriginalName('video.mp4')
            ->setTitle('Video')
            ->setMimeType('video/mp4')
            ->setFileSize(123);
        $asset->addReplica(
            (new MediaAssetReplica())
                ->setTargetKey('missing-target')
                ->setProviderKey('s3-compatible')
                ->setObjectKey('video/video.mp4')
                ->setLocation('https://media.example.test/video.mp4'),
        );
        $this->em($client)->persist($asset);
        $this->em($client)->flush();
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('POST', '/admin/storage/media/'.$assetId.'/delete', [
            '_token' => $this->csrf($client, 'delete-media-'.$assetId),
        ]);

        self::assertResponseRedirects('/admin/storage');
        $this->em($client)->clear();
        $stored = $this->em($client)->find(MediaAsset::class, $assetId);
        self::assertInstanceOf(MediaAsset::class, $stored);
        self::assertTrue($stored->isDeletionPending());
        self::assertCount(1, $stored->getReplicas());
    }

    public function testDisabledReplicaTargetLeavesRepairablePendingIntent(): void
    {
        $client = static::createClient();
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MEDIA)
            ->setTargetKey('disabled-media-'.bin2hex(random_bytes(4)))
            ->setProviderKey('s3-compatible')
            ->setDisplayName('Disabled target')
            ->setConfigurationReference('media.disabled')
            ->setRequired(false)
            ->setEnabled(false);
        $asset = (new MediaAsset())
            ->setModuleKey('video')
            ->setStorageMode('external')
            ->setLocation('https://media.example.test/disabled.mp4')
            ->setOriginalName('disabled.mp4')
            ->setTitle('Disabled target video')
            ->setMimeType('video/mp4')
            ->setFileSize(123);
        $asset->addReplica(
            (new MediaAssetReplica())
                ->setTargetKey($target->getTargetKey())
                ->setProviderKey($target->getProviderKey())
                ->setObjectKey('video/disabled.mp4')
                ->setLocation('https://media.example.test/disabled.mp4'),
        );
        $this->em($client)->persist($target);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();
        $assetId = $asset->getId();
        self::assertNotNull($assetId);
        $client->loginUser($this->user($client, [CmsPermission::STORAGE]));

        $client->request('POST', '/admin/storage/media/'.$assetId.'/delete', [
            '_token' => $this->csrf($client, 'delete-media-'.$assetId),
        ]);

        self::assertResponseRedirects('/admin/storage');
        $this->em($client)->clear();
        $stored = $this->em($client)->find(MediaAsset::class, $assetId);
        self::assertInstanceOf(MediaAsset::class, $stored);
        self::assertTrue($stored->isDeletionPending());
        self::assertCount(1, $stored->getReplicas());
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('media-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Media security test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function asset(KernelBrowser $client, string $name): MediaAsset
    {
        $asset = (new MediaAsset())
            ->setModuleKey('content')
            ->setStorageMode('internal')
            ->setLocation('/uploads/media/content/'.bin2hex(random_bytes(6)).'-'.$name)
            ->setOriginalName($name)
            ->setTitle($name)
            ->setMimeType('text/plain')
            ->setFileSize(10);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();

        return $asset;
    }

    private function csrf(KernelBrowser $client, string $id): string
    {
        return $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken($id)->getValue();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
