<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MediaStorageModuleGateTest extends WebTestCase
{
    public function testDirectMediaUploadRouteFailsClosedWhenMediaIsDisabled(): void
    {
        $client = static::createClient();
        $this->resetMediaState($client);
        $user = null;

        try {
            $this->setMediaState($client, false);
            $user = $this->user($client);
            $client->loginUser($user);

            $client->request('GET', '/admin/storage/media/upload');

            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $user);
        }
    }

    public function testDirectMediaUploadRouteRemainsAvailableWhenMediaIsEnabled(): void
    {
        $client = static::createClient();
        $this->resetMediaState($client);
        $user = null;

        try {
            $this->setMediaState($client, true);
            $user = $this->user($client);
            $client->loginUser($user);

            $client->request('GET', '/admin/storage/media/upload');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Datei hochladen');
        } finally {
            $this->cleanup($client, $user);
        }
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('media-module-gate-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Media module gate test')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::STORAGE])
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function setMediaState(KernelBrowser $client, bool $enabled): void
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('media')
            ?? (new CmsModuleState())->setModuleKey('media')->updateVersion('1.0.0');
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();
    }

    private function resetMediaState(KernelBrowser $client): void
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('media');
        if ($state !== null) {
            $em->remove($state);
        }
        $em->flush();
        $em->clear();
    }

    private function cleanup(KernelBrowser $client, ?User $user): void
    {
        $em = $this->em($client);
        if ($user?->getId() !== null) {
            $stored = $em->getRepository(User::class)->find($user->getId());
            if ($stored !== null) {
                $em->remove($stored);
            }
        }
        $state = $em->getRepository(CmsModuleState::class)->find('media');
        if ($state !== null) {
            $em->remove($state);
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
