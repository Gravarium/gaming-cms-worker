<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\ExternalConnector\DoctrineGuildNotificationRecipientResolver;
use App\Repository\GuildRepository;
use PHPUnit\Framework\TestCase;

final class DoctrineGuildNotificationRecipientResolverTest extends TestCase
{
    public function testRejectsRecipientIdBeyondPlatformIntegerRangeBeforeRepositoryLookup(): void
    {
        $reflection = new \ReflectionClass(GuildRepository::class);
        /** @var GuildRepository $guilds */
        $guilds = $reflection->newInstanceWithoutConstructor();
        $resolver = new DoctrineGuildNotificationRecipientResolver($guilds);

        self::assertNull($resolver->resolve('guild:'.((string) PHP_INT_MAX).'0'));
    }

    public function testMalformedReferencesRemainUnresolvedBeforeRepositoryLookup(): void
    {
        $reflection = new \ReflectionClass(GuildRepository::class);
        /** @var GuildRepository $guilds */
        $guilds = $reflection->newInstanceWithoutConstructor();
        $resolver = new DoctrineGuildNotificationRecipientResolver($guilds);

        foreach (['', 'guild:0', 'guild:01', 'guild:+1', 'other:1'] as $reference) {
            self::assertNull($resolver->resolve($reference), $reference);
        }
    }
}
