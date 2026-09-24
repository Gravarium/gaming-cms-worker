<?php
declare(strict_types=1);
namespace App\Downloads;
use App\Entity\Download\DownloadPackage;
use App\Entity\User;
use App\Security\CmsPermission;
final class DownloadAuthorization
{
    public function canDownload(DownloadPackage $package,?User $user):bool
    {
        if(!$package->isEnabled())return false;
        return match($package->getVisibility()){
            'public'=>true,
            'member'=>$user instanceof User&&$user->isActive()&&!$user->isLocked(),
            'admin'=>$user instanceof User&&$user->isActive()&&!$user->isLocked()&&$user->hasPermission(CmsPermission::STORAGE),
            default=>false,
        };
    }
}
