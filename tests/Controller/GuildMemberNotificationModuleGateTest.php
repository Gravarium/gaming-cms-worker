<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\MemberNotification;
use App\Entity\User;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GuildMemberNotificationModuleGateTest extends WebTestCase
{
    public function testMemberNotificationRoutesBelongToGamingAndFailClosedWhenDisabled(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(6));

        $user = (new User())
            ->setEmail('member-notification-'.$suffix.'@example.test')
            ->setDisplayName('Member notification test')
            ->verifyEmail();
        $game = (new Game())
            ->setName('Notification game '.$suffix)
            ->setSlug('notification-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Notification guild '.$suffix)
            ->setSlug('notification-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic guild for module-gate coverage.');
        $notification = (new MemberNotification())
            ->setUser($user)
            ->setGuild($guild)
            ->setTitle('Synthetic notification')
            ->setMessage('This notification must remain unread while Gaming is disabled.');

        foreach ([$user, $game, $guild, $notification] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $notificationId = $notification->getId();
        self::assertNotNull($notificationId);

        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        $stateWasPresent = $state instanceof CmsModuleState;
        $previousEnabled = $stateWasPresent ? $state->isEnabled() : null;
        if (!$stateWasPresent) {
            $state = (new CmsModuleState())
                ->setModuleKey('gaming')
                ->updateVersion('1.0.0');
            $entityManager->persist($state);
        }
        $state->setEnabled(true);
        $entityManager->flush();

        $client->loginUser($user);
        $route = '/guild-area/notification/'.$notificationId.'/read';
        $crawler = $client->request('GET', '/guild-area');
        self::assertResponseIsSuccessful();
        $csrf = (string) $crawler
            ->filter('form[action="'.$route.'"] input[name="_token"]')
            ->attr('value');
        self::assertNotSame('', $csrf);

        $modules = $client->getContainer()->get(CmsModuleManager::class);

        try {
            self::assertSame('gaming', $modules->moduleForRoute('app_member_notification_read'));
            self::assertSame('gaming', $modules->moduleForRoute('app_member_notification_read_all'));

            $state->setEnabled(false);
            $entityManager->flush();

            $client->request('POST', $route, ['_token' => $csrf]);
            self::assertResponseStatusCodeSame(404);

            $entityManager->clear();
            $stored = $entityManager->find(MemberNotification::class, $notificationId);
            self::assertInstanceOf(MemberNotification::class, $stored);
            self::assertNull($stored->getReadAt());

            $state = $entityManager->find(CmsModuleState::class, 'gaming');
            self::assertInstanceOf(CmsModuleState::class, $state);
            $state->setEnabled(true);
            $entityManager->flush();

            $client->request('POST', $route, ['_token' => $csrf]);
            self::assertResponseRedirects('/guild-area');

            $entityManager->clear();
            $stored = $entityManager->find(MemberNotification::class, $notificationId);
            self::assertInstanceOf(MemberNotification::class, $stored);
            self::assertNotNull($stored->getReadAt());
        } finally {
            $cleanupManager = $this->entityManager($client);
            $stored = $cleanupManager->find(MemberNotification::class, $notificationId);
            if ($stored instanceof MemberNotification) {
                $cleanupManager->remove($stored);
            }

            $storedGuild = $cleanupManager->find(Guild::class, $guild->getId());
            if ($storedGuild instanceof Guild) {
                $cleanupManager->remove($storedGuild);
            }

            $storedGame = $cleanupManager->find(Game::class, $game->getId());
            if ($storedGame instanceof Game) {
                $cleanupManager->remove($storedGame);
            }

            $storedUser = $cleanupManager->find(User::class, $user->getId());
            if ($storedUser instanceof User) {
                $cleanupManager->remove($storedUser);
            }

            $storedState = $cleanupManager->find(CmsModuleState::class, 'gaming');
            if ($stateWasPresent && $storedState instanceof CmsModuleState) {
                $storedState->setEnabled($previousEnabled ?? true);
            } elseif ($storedState instanceof CmsModuleState) {
                $cleanupManager->remove($storedState);
            }

            $cleanupManager->flush();
        }
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
