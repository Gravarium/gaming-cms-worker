<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ExternalConnectorTarget;
use App\Entity\MediaAsset;
use App\Entity\MediaAssetReplica;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ConnectorTargetSecurityTest extends WebTestCase
{
    public function testReferencedMediaTargetCannotBeDeleted(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $targetKey = 'protected-media-'.$suffix;
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MEDIA)
            ->setTargetKey($targetKey)
            ->setProviderKey('s3-compatible')
            ->setDisplayName('Protected media target')
            ->setPriority(100)
            ->setRequired(false)
            ->setEnabled(false)
            ->setConfigurationReference('media.'.$suffix);
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
                ->setTargetKey($targetKey)
                ->setProviderKey('s3-compatible')
                ->setObjectKey('video/video.mp4')
                ->setLocation('https://media.example.test/video.mp4'),
        );
        $this->em($client)->persist($target);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();
        $targetId = $target->getId();
        self::assertNotNull($targetId);

        $client->loginUser($this->user($client));
        $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('delete-connector-'.$targetId)
            ->getValue();
        $client->request('POST', '/admin/connectors/'.$targetId.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/connectors');
        $this->em($client)->clear();
        self::assertInstanceOf(
            ExternalConnectorTarget::class,
            $this->em($client)->find(ExternalConnectorTarget::class, $targetId),
        );
    }

    public function testUnreferencedNonMediaTargetCanStillBeDeleted(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_ANALYTICS)
            ->setTargetKey('analytics-'.$suffix)
            ->setProviderKey('development-placeholder')
            ->setDisplayName('Disposable target')
            ->setPriority(100)
            ->setRequired(false)
            ->setEnabled(false)
            ->setConfigurationReference('analytics.'.$suffix);
        $this->em($client)->persist($target);
        $this->em($client)->flush();
        $targetId = $target->getId();
        self::assertNotNull($targetId);

        $client->loginUser($this->user($client));
        $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('delete-connector-'.$targetId)
            ->getValue();
        $client->request('POST', '/admin/connectors/'.$targetId.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/connectors');
        $this->em($client)->clear();
        self::assertNull($this->em($client)->find(ExternalConnectorTarget::class, $targetId));
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('connector-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Connector security test')
            ->setPermissions([CmsPermission::CONNECTORS])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
