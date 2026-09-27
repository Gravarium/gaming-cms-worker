<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGamingGameModuleGateTest extends WebTestCase
{
    public function testDirectGameCreationRouteFailsClosedWhenGamingIsDisabled(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $user = null;

        try {
            $this->setModuleState($client, 'gaming', false);
            $user = $this->user($client);
            $client->loginUser($user);

            $client->request('GET', '/admin/gaming/game/new');

            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $user);
        }
    }

    public function testDirectGameCreationRouteRemainsAvailableWhenGamingIsEnabled(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $user = null;

        try {
            $this->setModuleState($client, 'gaming', true);
            $user = $this->user($client);
            $client->loginUser($user);

            $client->request('GET', '/admin/gaming/game/new');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Spiel anlegen');
        } finally {
            $this->cleanup($client, $user);
        }
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('gaming-game-module-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Gaming game module test')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::GAMING])
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function setModuleState(KernelBrowser $client, string $key, bool $enabled): void
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find($key)
            ?? (new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0');
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();
    }

    private function resetModuleStates(KernelBrowser $client): void
    {
        $em = $this->em($client);
        foreach (['content', 'gaming', 'users', 'notifications', 'operations'] as $key) {
            $state = $em->getRepository(CmsModuleState::class)->find($key);
            if ($state !== null) {
                $em->remove($state);
            }
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
        foreach (['content', 'gaming', 'users', 'notifications', 'operations'] as $key) {
            $state = $em->getRepository(CmsModuleState::class)->find($key);
            if ($state !== null) {
                $em->remove($state);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
