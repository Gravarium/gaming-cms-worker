<?php
declare(strict_types=1);
namespace App\ExternalConnector;
use App\Entity\Guild;
use App\Repository\GuildRepository;
final readonly class DoctrineGuildNotificationRecipientResolver implements GuildNotificationRecipientResolver
{
    public function __construct(private GuildRepository $guilds) {}
    public function resolve(string $recipientReference): ?Guild
    {
        if (preg_match('/^guild:([1-9][0-9]*)$/', $recipientReference, $matches) !== 1) { return null; }
        return $this->guilds->find((int) $matches[1]);
    }
}
