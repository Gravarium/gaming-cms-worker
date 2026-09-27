<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminMediaBatchUploadTest extends WebTestCase
{
    private const BATCH_URL = '/admin/storage/media/batch-upload';

    public function testBatchUploadRequiresStoragePermission(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $client->loginUser($user);

        try {
            $client->request('GET', self::BATCH_URL);

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->removeUser($client, $user);
        }
    }

    public function testBatchPageIsReachableFromSingleUploadAndIsPrivate(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $client->loginUser($user);

        try {
            $singleUpload = $client->request('GET', '/admin/storage/media/upload');
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(
                0,
                $singleUpload->filter('a[href="'.self::BATCH_URL.'"]')->count(),
            );

            $client->request('GET', self::BATCH_URL);
            self::assertResponseIsSuccessful();
            $this->assertPrivateResponse($client);
            self::assertSelectorExists('input[type="file"][multiple]');
            self::assertSelectorNotExists('select[name="media_asset_batch_upload[moduleKey]"] option[value="video"]');
        } finally {
            $this->removeUser($client, $user);
        }
    }

    public function testTwoSafeFilesAreStoredInTheSelectedModuleAndFolder(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $client->loginUser($user);

        $suffix = bin2hex(random_bytes(5));
        $folder = (new MediaFolder())
            ->setName('Batch '.$suffix)
            ->setSlug('batch-'.$suffix);
        $this->em($client)->persist($folder);
        $this->em($client)->flush();
        $folderId = $folder->getId();
        self::assertNotNull($folderId);

        $imageName = 'batch-image-'.$suffix.'.png';
        $documentName = 'batch-document-'.$suffix.'.txt';
        $imageContents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/KlsAAAAASUVORK5CYII=',
            true,
        );
        self::assertNotFalse($imageContents);
        $image = $this->createFile($imageName, $imageContents);
        $documentContents = 'Batch upload document '.$suffix;
        $document = $this->createFile($documentName, $documentContents);

        try {
            $crawler = $client->request('GET', self::BATCH_URL);
            $this->postBatch($client, [
                '_token' => $this->token($crawler),
                'moduleKey' => 'content',
                'folder' => (string) $folderId,
            ], [$image, $document]);

            self::assertResponseRedirects('/admin/storage?folder='.$folderId);

            $em = $this->em($client);
            $em->clear();
            foreach ([$imageName, $documentName] as $name) {
                $asset = $em->getRepository(MediaAsset::class)->findOneBy(['originalName' => $name]);
                self::assertInstanceOf(MediaAsset::class, $asset);
                self::assertSame('content', $asset->getModuleKey());
                self::assertSame($folderId, $asset->getFolder()?->getId());
                self::assertFalse($asset->isExternal());
                self::assertStringStartsWith('/uploads/media/content/', $asset->getLocation());
                $storedPath = (string) $client->getContainer()->getParameter('kernel.project_dir').'/public'.$asset->getLocation();
                self::assertFileExists($storedPath);
            }

            $imageAsset = $em->getRepository(MediaAsset::class)->findOneBy(['originalName' => $imageName]);
            $documentAsset = $em->getRepository(MediaAsset::class)->findOneBy(['originalName' => $documentName]);
            self::assertInstanceOf(MediaAsset::class, $imageAsset);
            self::assertInstanceOf(MediaAsset::class, $documentAsset);
            self::assertSame(hash('sha256', $imageContents), $imageAsset->getChecksumSha256());
            self::assertSame(hash('sha256', $documentContents), $documentAsset->getChecksumSha256());
        } finally {
            $this->removeAssets($client, [$imageName, $documentName]);
            $this->removeFolder($client, $folderId);
            $this->removeUser($client, $user);
            @unlink($image['path']);
            @unlink($document['path']);
        }
    }

    public function testMissingAndInvalidCsrfTokensCreateNoAssets(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $client->loginUser($user);
        $before = $this->mediaCount($client);

        $first = $this->createFile('csrf-missing-'.bin2hex(random_bytes(4)).'.txt', 'csrf upload');
        $second = $this->createFile('csrf-invalid-'.bin2hex(random_bytes(4)).'.txt', 'csrf upload');

        try {
            $this->postBatch($client, ['moduleKey' => 'content'], [$first]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($before, $this->mediaCount($client));

            $this->postBatch($client, [
                '_token' => 'not-a-valid-token',
                'moduleKey' => 'content',
            ], [$second]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($before, $this->mediaCount($client));
        } finally {
            $this->removeAssets($client, [$first['name'], $second['name']]);
            $this->removeUser($client, $user);
            @unlink($first['path']);
            @unlink($second['path']);
        }
    }

    public function testEmptyOversizedAndUnsupportedBatchesAreRejected(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $client->loginUser($user);
        $before = $this->mediaCount($client);
        $page = $client->request('GET', self::BATCH_URL);
        $token = $this->token($page);
        $suffix = bin2hex(random_bytes(4));
        $tooMany = [];
        for ($index = 0; $index < 11; ++$index) {
            $tooMany[] = $this->createFile('many-'.$suffix.'-'.$index.'.txt', 'item '.$index.' '.$suffix);
        }
        $large = $this->createLargeFile('large-'.$suffix.'.txt', 25 * 1024 * 1024 + 1);
        $svg = $this->createFile(
            'unsafe-'.$suffix.'.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        try {
            $this->postBatch($client, ['_token' => $token, 'moduleKey' => 'content']);
            self::assertResponseStatusCodeSame(422);

            $this->postBatch($client, ['_token' => $token, 'moduleKey' => 'content'], $tooMany);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'höchstens 10');

            $this->postBatch($client, ['_token' => $token, 'moduleKey' => 'content'], [$large]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', '25 MB');

            $this->postBatch($client, ['_token' => $token, 'moduleKey' => 'content'], [$svg]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Dateiinhalt');

            self::assertSame($before, $this->mediaCount($client));
        } finally {
            $names = array_merge(
                array_column($tooMany, 'name'),
                [$large['name'], $svg['name']],
            );
            $this->removeAssets($client, $names);
            $this->removeUser($client, $user);
            foreach (array_merge($tooMany, [$large, $svg]) as $upload) {
                @unlink($upload['path']);
            }
        }
    }

    public function testExistingAndSameBatchDuplicatesAreRejectedBeforeStorage(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $client->loginUser($user);
        $suffix = bin2hex(random_bytes(5));
        $duplicateContents = 'existing duplicate '.$suffix;
        $existingName = 'library-duplicate-'.$suffix.'.txt';
        $existing = (new MediaAsset())
            ->setModuleKey('content')
            ->setStorageMode('internal')
            ->setLocation('/uploads/media/content/missing-'.$suffix.'.txt')
            ->setOriginalName($existingName)
            ->setTitle('Existing duplicate')
            ->setMimeType('text/plain')
            ->setFileSize(strlen($duplicateContents))
            ->setChecksumSha256(hash('sha256', $duplicateContents));
        $this->em($client)->persist($existing);
        $this->em($client)->flush();
        $before = $this->mediaCount($client);

        $existingUpload = $this->createFile('upload-duplicate-'.$suffix.'.txt', $duplicateContents);
        $sameBatchA = $this->createFile('same-a-'.$suffix.'.txt', 'same batch '.$suffix);
        $sameBatchB = $this->createFile('same-b-'.$suffix.'.txt', 'same batch '.$suffix);

        try {
            $page = $client->request('GET', self::BATCH_URL);
            $token = $this->token($page);

            $this->postBatch($client, ['_token' => $token, 'moduleKey' => 'content'], [$existingUpload]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'existiert bereits');
            self::assertSame($before, $this->mediaCount($client));

            $this->postBatch($client, ['_token' => $token, 'moduleKey' => 'content'], [$sameBatchA, $sameBatchB]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'dieselbe Datei mehrfach');
            self::assertSame($before, $this->mediaCount($client));
        } finally {
            $this->removeAssets($client, [
                $existingName,
                $existingUpload['name'],
                $sameBatchA['name'],
                $sameBatchB['name'],
            ]);
            $this->removeUser($client, $user);
            @unlink($existingUpload['path']);
            @unlink($sameBatchA['path']);
            @unlink($sameBatchB['path']);
        }
    }

    public function testLaterStorageFailureRemovesEarlierFileAndPendingAsset(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::STORAGE]);
        $client->loginUser($user);
        $suffix = bin2hex(random_bytes(5));
        $firstName = 'batch-fail-'.$suffix.'.txt';
        $unsafeName = 'batch-fail-unsafe-'.$suffix.'.php.txt';
        $first = $this->createFile($firstName, 'first file staged before the later failure');
        $unsafe = $this->createFile($unsafeName, 'second file has a dangerous intermediate extension');
        $before = $this->mediaCount($client);

        try {
            $page = $client->request('GET', self::BATCH_URL);
            $this->postBatch($client, [
                '_token' => $this->token($page),
                'moduleKey' => 'content',
            ], [$first, $unsafe]);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'gefährliche Erweiterung');

            // A later request/flush must not commit an asset whose stored file was already discarded.
            $this->em($client)->flush();
            $this->assertNoAssets($client, [$firstName, $unsafeName]);
            self::assertSame([], $this->storedFiles($client, 'content', 'batch-fail-'.$suffix.'-*.txt'));
            self::assertSame($before, $this->mediaCount($client));
        } finally {
            $this->removeAssets($client, [$firstName, $unsafeName]);
            $this->removeUser($client, $user);
            @unlink($first['path']);
            @unlink($unsafe['path']);
        }
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('batch-media-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Media batch test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    /** @param array<string, mixed> $values
     *  @param list<array{path: string, name: string}> $uploads
     */
    private function postBatch(KernelBrowser $client, array $values, array $uploads = []): void
    {
        $fileBag = [];
        if ($uploads !== []) {
            $files = [];
            foreach ($uploads as $upload) {
                $files[] = new UploadedFile($upload['path'], $upload['name'], null, UPLOAD_ERR_OK, true);
            }
            $fileBag = ['media_asset_batch_upload' => ['files' => $files]];
        }

        $client->request('POST', self::BATCH_URL, [
            'media_asset_batch_upload' => $values,
        ], $fileBag);
    }

    private function token(Crawler $crawler): string
    {
        $token = $crawler->filter('input[name="media_asset_batch_upload[_token]"]')->attr('value');
        if (!is_string($token) || $token === '') {
            self::fail('The batch upload form did not render a CSRF token.');
        }

        return $token;
    }

    /** @return array{path: string, name: string} */
    private function createFile(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'media-batch-');
        if ($path === false) {
            self::fail('Unable to create a temporary upload file.');
        }
        if (file_put_contents($path, $contents) === false) {
            self::fail('Unable to write a temporary upload file.');
        }

        return ['path' => $path, 'name' => $name];
    }

    /** @return array{path: string, name: string} */
    private function createLargeFile(string $name, int $size): array
    {
        $path = tempnam(sys_get_temp_dir(), 'media-batch-large-');
        if ($path === false) {
            self::fail('Unable to create a large temporary upload file.');
        }

        $stream = fopen($path, 'wb');
        if ($stream === false) {
            self::fail('Unable to open a large temporary upload file.');
        }

        $remaining = $size;
        while ($remaining > 0) {
            $chunk = str_repeat('a', min(1024 * 1024, $remaining));
            $written = fwrite($stream, $chunk);
            if ($written === false || $written !== strlen($chunk)) {
                fclose($stream);
                self::fail('Unable to write a large temporary upload file.');
            }
            $remaining -= $written;
        }
        fclose($stream);

        return ['path' => $path, 'name' => $name];
    }

    private function mediaCount(KernelBrowser $client): int
    {
        return $this->em($client)->getRepository(MediaAsset::class)->count([]);
    }

    /** @param list<string> $names */
    private function assertNoAssets(KernelBrowser $client, array $names): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($names as $name) {
            self::assertNull($em->getRepository(MediaAsset::class)->findOneBy(['originalName' => $name]));
        }
    }

    /** @param list<string> $originalNames */
    private function removeAssets(KernelBrowser $client, array $originalNames): void
    {
        $em = $this->em($client);
        if (!$em->isOpen()) {
            return;
        }
        $em->clear();

        foreach (array_unique($originalNames) as $originalName) {
            $asset = $em->getRepository(MediaAsset::class)->findOneBy(['originalName' => $originalName]);
            if (!$asset instanceof MediaAsset) {
                continue;
            }

            $location = $asset->getLocation();
            if (str_starts_with($location, '/uploads/media/')) {
                $storedPath = (string) $client->getContainer()->getParameter('kernel.project_dir').'/public'.$location;
                if (is_file($storedPath)) {
                    @unlink($storedPath);
                }
            }
            $em->remove($asset);
        }

        $em->flush();
    }

    private function removeFolder(KernelBrowser $client, ?int $folderId): void
    {
        if ($folderId === null) {
            return;
        }

        $em = $this->em($client);
        if (!$em->isOpen()) {
            return;
        }
        $em->clear();
        $folder = $em->find(MediaFolder::class, $folderId);
        if ($folder instanceof MediaFolder) {
            $em->remove($folder);
            $em->flush();
        }
    }

    private function removeUser(KernelBrowser $client, User $user): void
    {
        $userId = $user->getId();
        if ($userId === null) {
            return;
        }

        $em = $this->em($client);
        if (!$em->isOpen()) {
            return;
        }
        $em->clear();
        $stored = $em->find(User::class, $userId);
        if ($stored instanceof User) {
            $em->remove($stored);
            $em->flush();
        }
    }

    private function storedFiles(KernelBrowser $client, string $module, string $pattern): array
    {
        $root = (string) $client->getContainer()->getParameter('kernel.project_dir');
        $matches = glob($root.'/public/uploads/media/'.$module.'/'.$pattern);

        return $matches === false ? [] : $matches;
    }

    private function assertPrivateResponse(KernelBrowser $client): void
    {
        $headers = $client->getResponse()->headers;
        $cacheControl = strtolower((string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('noindex, nofollow, noarchive', $headers->get('X-Robots-Tag'));
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
