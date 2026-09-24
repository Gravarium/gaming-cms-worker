<?php
declare(strict_types=1);
namespace App\Downloads;
interface DownloadPolicyPort
{
    public function allows(string $action,?int $actorUserId,int $packageId):bool;
}
