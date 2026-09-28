<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\PreUpdate;
use PHPUnit\Framework\TestCase;

final class UserDisplayNameBoundaryTest extends TestCase
{
    public function testDisplayNameAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiName = str_repeat('P', 80);
        self::assertSame($asciiName, (new User())->setDisplayName($asciiName)->getDisplayName());

        $multibyteName = str_repeat('🎮', 80);
        self::assertSame(320, strlen($multibyteName));
        self::assertSame($multibyteName, (new User())->setDisplayName($multibyteName)->getDisplayName());
    }

    public function testTrimmedDisplayNameAtBoundaryKeepsCurrentNormalization(): void
    {
        $name = str_repeat('P', 80);
        $user = new User();

        self::assertSame($name, $user->setDisplayName('  '.$name.'  ')->getDisplayName());
        self::assertSame('Player One', $user->setDisplayName('  Player One  ')->getDisplayName());
        self::assertSame('', $user->setDisplayName('   ')->getDisplayName());
    }

    public function testOverlongNamesRemainAvailableForValidationButAreRejectedBeforePersistence(): void
    {
        self::assertCount(1, (new \ReflectionClass(User::class))->getAttributes(HasLifecycleCallbacks::class));
        $boundary = new \ReflectionMethod(User::class, 'assertDisplayNameColumnBoundary');
        self::assertCount(1, $boundary->getAttributes(PrePersist::class));
        self::assertCount(1, $boundary->getAttributes(PreUpdate::class));

        $user = new User();
        foreach ([str_repeat('P', 81), str_repeat('é', 81), str_repeat('🎮', 81)] as $candidate) {
            $user->setDisplayName($candidate);
            self::assertSame($candidate, $user->getDisplayName());

            try {
                $user->assertDisplayNameColumnBoundary();
                self::fail('An out-of-column display name reached persistence.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            self::assertSame($candidate, $user->getDisplayName());
        }
    }

    public function testMalformedUtf8AndNulAreRejectedWithoutReplacingTheStoredValue(): void
    {
        $user = (new User())->setDisplayName('Player');

        foreach (["\xFFname", "\0name", "name\0"] as $candidate) {
            try {
                $user->setDisplayName($candidate);
                self::fail('Malformed UTF-8 or a NUL byte was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            self::assertSame('Player', $user->getDisplayName());
        }
    }
}
