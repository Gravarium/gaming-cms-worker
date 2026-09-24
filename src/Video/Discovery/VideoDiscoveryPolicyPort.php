<?php
declare(strict_types=1);
namespace App\Video\Discovery;
/** Public Fortress-ready seam. Implementations must deny on incomplete/unavailable context. */
interface VideoDiscoveryPolicyPort
{
    public function allows(string $action,?int $actorUserId,int $videoId):bool;
}
