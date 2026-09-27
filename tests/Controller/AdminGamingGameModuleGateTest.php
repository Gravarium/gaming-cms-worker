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
        $moduleState = $this->snapshotModuleState($client);
        $user = null;

        try {
            $this->setModuleEnabled($client, false);
            $user = $this->user($client);
            $client->loginUser($user);
            $client->request('GET', '/admin/gaming/game/new');

            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $user, $moduleState);
        }
    }

    public function testDirectGameCreationRouteRemainsAvailableWhenGamingIsEnabled(): void
    {
        $client = static::createClient();
        $moduleState = $this->snapshotModuleState($client);
        $user = null;

        try {
            $this->setModuleEnabled($client, true);
            $user = $this->user($client);
            $client->loginUser($user);
            $client->request('GET', '/admin/gaming/game/new');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Spiel anlegen');
        } finally {
            $this->cleanup($client, $user, $moduleState);
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

    /** @return array{present: bool, enabled: bool|null} */
    private function snapshotModuleState(KernelBrowser $client): array
    {
        $state = $this->em($client)->getRepository(CmsModuleState::class)->find('gaming');

        return [
            'present' => $state instanceof CmsModuleState,
            'enabled' => $state instanceof CmsModuleState ? $state->isEnabled() : null,
        ];
    }

    private function setModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('gaming')
            ?? (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();
    }

    /**
     * @param array{present: bool, enabled: bool|null} $moduleSnapshot
     */
    private function cleanup(KernelBrowser $client, ?User $user, array $moduleSnapshot): void
    {
        $em = $this->em($client);
        if ($user?->getId() !== null) {
            $stored = $em->getRepository(User::class)->find($user->getId());
            if ($stored !== null) {
                $em->remove($stored);
            }
        }

        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        if ($moduleSnapshot['present']) {
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
                $em->persist($state);
            }
            if ($moduleSnapshot['enabled'] !== null) {
                $state->setEnabled($moduleSnapshot['enabled']);
            }
        } elseif ($state instanceof CmsModuleState) {
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
