<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCharacter\CharacterAccount;
use App\Entity\GameCharacter\CharacterProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CharacterSelfServiceTest extends WebTestCase
{
    public function testOwnerCanCreateEditAndRetirePrivateAccountAndProfile(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em);
        $game = $this->game($em);
        $gameId = $game->getId();
        self::assertIsInt($gameId);
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/account/characters/accounts/new');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/account/characters/accounts/new', [
            '_token' => $token, 'game_id' => (string) $gameId,
            'account_key' => 'owner-key-'.bin2hex(random_bytes(4)),
            'display_name' => 'Meine Sammlung', 'consent' => '1',
        ]);
        self::assertResponseRedirects('/account/characters');

        $em = $this->em($client);
        $account = $em->getRepository(CharacterAccount::class)->findOneBy(['owner' => $em->find(User::class, $owner->getId())]);
        self::assertInstanceOf(CharacterAccount::class, $account);
        self::assertTrue($account->hasConsent());
        $accountId = $account->getId();
        self::assertIsInt($accountId);

        $crawler = $client->request('GET', '/account/characters/profiles/new');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/account/characters/profiles/new', [
            '_token' => $token, 'account_id' => (string) $accountId,
            'name' => 'Heldin', 'server' => 'EU-1', 'region' => 'EU',
            'character_class' => 'Magierin', 'role' => 'DPS', 'level' => '60',
        ]);
        self::assertResponseRedirects('/account/characters');

        $em = $this->em($client);
        $profile = $em->getRepository(CharacterProfile::class)->findOneBy(['account' => $em->find(CharacterAccount::class, $accountId)]);
        self::assertInstanceOf(CharacterProfile::class, $profile);
        self::assertFalse($profile->isPubliclyVisible());
        self::assertSame('Heldin', $profile->getName());
        $profileId = $profile->getId();
        self::assertIsInt($profileId);

        $crawler = $client->request('GET', '/account/characters/profiles/'.$profileId.'/edit');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/account/characters/profiles/'.$profileId.'/edit', [
            '_token' => $token, 'name' => 'Heldin Zwei', 'server' => '',
            'region' => 'EU', 'character_class' => 'Magierin', 'role' => 'Heiler', 'level' => '',
        ]);
        self::assertResponseRedirects('/account/characters');

        $em = $this->em($client);
        $profile = $em->find(CharacterProfile::class, $profileId);
        self::assertInstanceOf(CharacterProfile::class, $profile);
        self::assertSame('Heldin Zwei', $profile->getName());
        self::assertNull($profile->getLevel());
        self::assertFalse($profile->isPubliclyVisible());

        $crawler = $client->request('GET', '/account/characters');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($account->getAccountKey(), (string) $client->getResponse()->getContent());
        $delete = '/account/characters/profiles/'.$profileId.'/delete';
        $token = (string) $crawler->filter('form[action="'.$delete.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $delete, ['_token' => $token]);
        self::assertResponseRedirects('/account/characters');
        $em = $this->em($client);
        $profile = $em->find(CharacterProfile::class, $profileId);
        self::assertInstanceOf(CharacterProfile::class, $profile);
        self::assertTrue($profile->isDeleted());

        $crawler = $client->request('GET', '/account/characters');
        $delete = '/account/characters/accounts/'.$accountId.'/delete';
        $token = (string) $crawler->filter('form[action="'.$delete.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $delete, ['_token' => $token]);
        self::assertResponseRedirects('/account/characters');
        $em = $this->em($client);
        $account = $em->find(CharacterAccount::class, $accountId);
        self::assertInstanceOf(CharacterAccount::class, $account);
        self::assertTrue($account->isDeleted());
        self::assertFalse($account->hasConsent());
    }

    public function testForeignAndImportedProfilesCannotBeManuallyEdited(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em);
        $other = $this->user($em);
        $game = $this->game($em);
        $account = (new CharacterAccount($owner, $game, 'foreign-key', 'Foreign'))->grantConsent();
        $imported = new CharacterProfile($account, 'Imported', CharacterProfile::SOURCE_IMPORTED, 'external-1');
        $em->persist($account);
        $em->persist($imported);
        $em->flush();
        $accountId = $account->getId();
        $profileId = $imported->getId();
        self::assertIsInt($accountId);
        self::assertIsInt($profileId);

        $client->loginUser($other);
        $client->request('GET', '/account/characters/accounts/'.$accountId.'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/account/characters/profiles/'.$profileId.'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/account/characters/profiles/'.$profileId.'/delete');
        self::assertResponseStatusCodeSame(404);

        $client->restart();
        $client->loginUser($owner);
        $client->request('GET', '/account/characters/profiles/'.$profileId.'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/account/characters/accounts/'.$accountId.'/delete');
        self::assertResponseStatusCodeSame(403);

        $em = $this->em($client);
        $account = $em->find(CharacterAccount::class, $accountId);
        self::assertInstanceOf(CharacterAccount::class, $account);
        self::assertFalse($account->isDeleted());
    }

    public function testInvalidInputAndDisabledGameOrGamingModuleFailClosed(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em);
        $game = $this->game($em);
        $gameId = $game->getId();
        self::assertIsInt($gameId);
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/account/characters/accounts/new');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/account/characters/accounts/new', [
            '_token' => $token, 'game_id' => (string) $gameId,
            'account_key' => 'new-key', 'display_name' => 'No consent',
        ]);
        self::assertResponseStatusCodeSame(422);

        $em = $this->em($client);
        $storedGame = $em->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $storedGame);
        $storedGame->setEnabled(false);
        $em->flush();
        $client->request('POST', '/account/characters/accounts/new', [
            '_token' => $token, 'game_id' => (string) $gameId,
            'account_key' => 'new-key', 'display_name' => 'Disabled', 'consent' => '1',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em($client)->getRepository(CharacterAccount::class)->count(['owner' => $owner]));

        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'gaming');
        $created = !$state instanceof CmsModuleState;
        $wasEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())->setModuleKey('gaming');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();
        try {
            $client->request('GET', '/account/characters');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $this->em($client);
            $state = $em->find(CmsModuleState::class, 'gaming');
            self::assertInstanceOf(CmsModuleState::class, $state);
            if ($created) {
                $em->remove($state);
            } else {
                $state->setEnabled($wasEnabled);
            }
            $storedGame = $em->find(Game::class, $gameId);
            self::assertInstanceOf(Game::class, $storedGame);
            $storedGame->setEnabled(true);
            $em->flush();
        }
    }

    private function user(EntityManagerInterface $em): User
    {
        $user = (new User())
            ->setEmail('character-owner-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Character owner')
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function game(EntityManagerInterface $em): Game
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())->setName('Character Game '.$suffix)->setSlug('character-game-'.$suffix)->setEnabled(true);
        $em->persist($game);
        $em->flush();

        return $game;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
