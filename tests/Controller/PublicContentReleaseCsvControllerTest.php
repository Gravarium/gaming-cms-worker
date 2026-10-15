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

final class PublicContentReleaseCsvControllerTest extends WebTestCase
{
    public function testCsvContainsOnlyPublicReleasesAcrossPagesWithSafeDownloadHeaders(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em($client);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $author = $this->author($em, $suffix);
            $now = new \DateTimeImmutable();
            for ($number = 1; $number <= 21; ++$number) {
                $entry = $this->entry($author, $suffix.'-'.$number);
                $name = $number === 21 ? '=SUM(1,2)' : sprintf('Public release %s %02d', $suffix, $number);
                $release = (new ContentRelease())
                    ->setName($name)
                    ->setDescription($number === 21 ? 'Description, with "quotes"' : 'Release description')
                    ->setCreatedBy($author)
                    ->addEntry($entry);
                $release->publish($now->modify('-'.(22 - $number).' minutes'));
                $em->persist($entry);
                $em->persist($release);
            }

            $draft = (new ContentRelease())
                ->setName('Private draft '.$suffix)
                ->setDescription('Never export')
                ->setCreatedBy($author);
            $em->persist($draft);
            $em->flush();

            $client->request('GET', '/releases.csv');

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertStringContainsString('attachment; filename=content-releases.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));

            $stream = fopen('php://temp', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, (string) $client->getResponse()->getContent());
            rewind($stream);
            self::assertSame(['Release', 'Description', 'Published at'], fgetcsv($stream, null, ',', '"', ''));

            $rows = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rows[] = $row;
            }
            fclose($stream);

            self::assertCount(21, $rows);
            self::assertSame("'=SUM(1,2)", $rows[0][0]);
            self::assertSame('Description, with "quotes"', $rows[0][1]);
            self::assertStringContainsString('T', $rows[0][2]);
            self::assertNotContains('Private draft '.$suffix, array_column($rows, 0));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $em->clear();
        }
    }

    public function testDisabledContentModuleHidesCsvExport(): void
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
            $client->request('GET', '/releases.csv');
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

    private function author(EntityManagerInterface $em, string $suffix): User
    {
        $user = (new User())
            ->setEmail('release-csv-'.$suffix.'@example.test')
            ->setDisplayName('Content release CSV test')
            ->verifyEmail();
        $em->persist($user);

        return $user;
    }

    private function entry(User $author, string $slug): ContentEntry
    {
        return (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Release entry '.$slug)
            ->setSlug('release-entry-'.$slug)
            ->setExcerpt('Release summary')
            ->setBody('Public release entry.')
            ->setStatus(ContentEntry::STATUS_DRAFT);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
