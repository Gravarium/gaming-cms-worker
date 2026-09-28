<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CredentialRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\TrustPath\TrustPath;

final class CredentialRecordNameBoundaryTest extends TestCase
{
    public function testConstructorTrimsAndBoundsAsciiAndMultibyteNames(): void
    {
        $asciiName = str_repeat('p', 80);
        self::assertSame($asciiName, $this->createCredential($asciiName)->getName());

        $multibyteName = str_repeat('🎮', 80);
        self::assertSame(80, mb_strlen($multibyteName, 'UTF-8'));
        self::assertSame(320, strlen($multibyteName));
        self::assertSame($multibyteName, $this->createCredential($multibyteName)->getName());

        $trimmedOverflow = '  '.str_repeat('🎮', 81).'  ';
        $expected = str_repeat('🎮', 80);
        $credential = $this->createCredential($trimmedOverflow);

        self::assertSame($expected, $credential->getName());
        self::assertSame(80, mb_strlen($credential->getName(), 'UTF-8'));
        self::assertSame(320, strlen($credential->getName()));
    }

    public function testConstructorAndRenamePreserveTheSameTrimAndTruncationBehavior(): void
    {
        $input = '  '.str_repeat('P', 81).'  ';
        $expected = str_repeat('P', 80);

        $constructed = $this->createCredential($input);
        $renamed = $this->createCredential('Old passkey');
        $renamed->rename($input);

        self::assertSame('Passkey', $this->createCredential('  Passkey  ')->getName());
        self::assertSame($expected, $constructed->getName());
        self::assertSame($expected, $renamed->getName());
        self::assertSame($constructed->getName(), $renamed->getName());
    }

    private function createCredential(string $name): CredentialRecord
    {
        return new CredentialRecord(
            publicKeyCredentialId: 'credential-id',
            type: 'public-key',
            transports: [],
            attestationType: 'none',
            trustPath: $this->createStub(TrustPath::class),
            aaguid: Uuid::v4(),
            credentialPublicKey: 'credential-public-key',
            userHandle: 'user-handle',
            counter: 0,
            name: $name,
        );
    }
}
