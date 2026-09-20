<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\Guild;
interface GuildWebhookSender
{
    public function notify(Guild $guild, string $type, string $title, string $message): bool;
}
