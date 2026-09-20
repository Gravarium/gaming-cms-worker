<?php
declare(strict_types=1);
namespace App\ExternalConnector;
use App\Entity\Guild;
interface GuildNotificationRecipientResolver
{
    public function resolve(string $recipientReference): ?Guild;
}
