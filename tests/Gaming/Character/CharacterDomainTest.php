<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Character;

use App\Entity\Game;
use App\Entity\User;
use App\Entity\GameCharacter\CharacterAccount;
use App\Entity\GameCharacter\CharacterProfile;
use App\Gaming\Character\CharacterRefreshService;
use App\Gaming\Character\CharacterRefreshSnapshot;
use App\Gaming\Character\CharacterVisibilityPolicy;
use PHPUnit\Framework\TestCase;

final class CharacterDomainTest extends TestCase
{
    public function testMainAndAltProfilesStayInsideOneGameAccount(): void
    {
        $owner = (new User())->setEmail('owner@example.test')->setDisplayName('Owner');
        $game = (new Game())->setName('Game')->setSlug('game');
        $account = new CharacterAccount($owner, $game, 'account-1', 'Main account');
        $main = new CharacterProfile($account, 'Main');
        $alt = new CharacterProfile($account, 'Alt');
        $alt->setMainProfile($main);

        self::assertSame($main, $alt->getMainProfile());

        $otherAccount = new CharacterAccount($owner, $game, 'account-2', 'Other account');
        $other = new CharacterProfile($otherAccount, 'Other');
        $this->expectException(\DomainException::class);
        $alt->setMainProfile($other);
    }

    public function testFieldConsentAndOwnerVisibilityAreFailClosed(): void
    {
        $owner = (new User())->setEmail('owner2@example.test')->setDisplayName('Owner');
        $viewer = (new User())->setEmail('viewer@example.test')->setDisplayName('Viewer');
        $game = (new Game())->setName('Game')->setSlug('game-2');
        $account = new CharacterAccount($owner, $game, 'account-3', 'Account');
        $account->grantConsent();
        $profile = new CharacterProfile($account, 'Visible');
        $profile->setServer('EU-1')->setRole('tank')->setPubliclyVisible(true);
        $profile->grantFieldConsent(CharacterProfile::FIELD_NAME);
        $profile->grantFieldConsent(CharacterProfile::FIELD_SERVER);

        $policy = new CharacterVisibilityPolicy();
        self::assertTrue($policy->canViewProfile($profile, null));
        self::assertTrue($policy->canViewField($profile, null, CharacterProfile::FIELD_SERVER));
        self::assertFalse($policy->canViewField($profile, null, CharacterProfile::FIELD_ROLE));
        self::assertTrue($policy->canViewField($profile, $owner, CharacterProfile::FIELD_ROLE));
        self::assertFalse($policy->canViewField($profile, $viewer, 'unknown'));
        $profile->revokeFieldConsent(CharacterProfile::FIELD_SERVER);
        self::assertFalse($policy->canViewField($profile, null, CharacterProfile::FIELD_SERVER));
    }

    public function testImportedRefreshIsConflictSafeAndRecordsProvenance(): void
    {
        $owner = (new User())->setEmail('owner3@example.test')->setDisplayName('Owner');
        $game = (new Game())->setName('Game')->setSlug('game-3');
        $account = new CharacterAccount($owner, $game, 'external-account', 'Imported', CharacterAccount::SOURCE_IMPORTED);
        $profile = new CharacterProfile($account, 'Imported hero', CharacterProfile::SOURCE_IMPORTED, 'hero-1');
        $service = new CharacterRefreshService();
        $snapshot = new CharacterRefreshSnapshot('provider', 'hero-1', 'Refreshed hero', 'S1', 'EU', 'Mage', 'dps', 60, ['id' => 'b1'], ['alchemy' => 100], ['level' => 60], ['mounts' => 2], 'hash-1', new \DateTimeImmutable('2026-09-25T00:00:00Z'));

        $service->refresh($profile, $snapshot, 0);
        self::assertSame('Refreshed hero', $profile->getName());
        self::assertSame(1, $profile->getRefreshVersion());
        self::assertCount(1, $profile->getProvenance());

        $this->expectException(\DomainException::class);
        $service->refresh($profile, $snapshot, 0);
    }

    public function testDeletedProfilesNeverBecomeVisible(): void
    {
        $owner = (new User())->setEmail('owner4@example.test')->setDisplayName('Owner');
        $game = (new Game())->setName('Game')->setSlug('game-4');
        $account = new CharacterAccount($owner, $game, 'account-4', 'Account');
        $account->grantConsent();
        $profile = new CharacterProfile($account, 'Deleted');
        $profile->setPubliclyVisible(true)->grantFieldConsent(CharacterProfile::FIELD_NAME)->markDeleted();

        self::assertFalse((new CharacterVisibilityPolicy())->canViewProfile($profile, null));
    }
}
