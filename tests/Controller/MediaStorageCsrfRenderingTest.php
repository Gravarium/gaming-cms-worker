<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MediaStorageCsrfRenderingTest extends WebTestCase
{
    public function testRenderedMutationTokensAreUniqueAndNonEmpty(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);

        $user = (new User())
            ->setEmail('media-csrf-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Media CSRF rendering test')
            ->setPermissions([CmsPermission::STORAGE])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $folder = (new MediaFolder())
            ->setName('CSRF folder')
            ->setSlug('csrf-folder-'.bin2hex(random_bytes(4)));
        $asset = (new MediaAsset())
            ->setModuleKey('content')
            ->setStorageMode('internal')
            ->setLocation('/uploads/media/content/'.bin2hex(random_bytes(6)).'-csrf.txt')
            ->setOriginalName('csrf.txt')
            ->setTitle('CSRF asset')
            ->setMimeType('text/plain')
            ->setFileSize(10)
            ->setFolder($folder);

        foreach ([$user, $folder, $asset] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        self::assertNotNull($folder->getId());
        self::assertNotNull($asset->getId());

        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/storage');
        self::assertResponseIsSuccessful();

        foreach ([
            '#media-bulk-form input[name="_token"]',
            'form[action="/admin/storage/folders/'.$folder->getId().'/delete"] input[name="_token"]',
            'form[action="/admin/storage/media/'.$asset->getId().'/delete"] input[name="_token"]',
        ] as $selector) {
            $nodes = $crawler->filter($selector);
            self::assertCount(1, $nodes, 'Expected exactly one rendered CSRF token for selector '.$selector);
            $token = $nodes->attr('value');
            self::assertIsString($token);
            self::assertNotSame('', $token, 'Rendered CSRF token must not be empty for selector '.$selector);
        }
    }
}
