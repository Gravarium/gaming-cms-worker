<?php
declare(strict_types=1);
namespace App\Video\Discovery;
use App\Entity\User;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoClip;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoWatchlist;
final class VideoVisibilityPolicy
{
    public function canViewProfile(VideoDiscoveryProfile $profile,?User $viewer):bool
    {
        if(!$profile->isDiscoverable()||!$profile->getVideo()->isPublished())return false;
        return match($profile->getVisibility()){
            VideoDiscoveryProfile::VISIBILITY_PUBLIC=>true,
            VideoDiscoveryProfile::VISIBILITY_MEMBER=>$viewer instanceof User&&$viewer->isActive()&&!$viewer->isLocked(),
            VideoDiscoveryProfile::VISIBILITY_PRIVATE=>$viewer instanceof User&&$profile->getCreator()?->getOwner()?->getId()!==null&&$profile->getCreator()?->getOwner()?->getId()===$viewer->getId(),
            default=>false,
        };
    }
    public function canViewCreator(CreatorProfile $creator,?User $viewer):bool
    {
        return match($creator->getVisibility()){
            CreatorProfile::VISIBILITY_PUBLIC=>true,
            CreatorProfile::VISIBILITY_MEMBER=>$viewer instanceof User&&$viewer->isActive()&&!$viewer->isLocked(),
            CreatorProfile::VISIBILITY_PRIVATE=>$viewer instanceof User&&$creator->getOwner()?->getId()!==null&&$creator->getOwner()?->getId()===$viewer->getId(),
            default=>false,
        };
    }
    public function canViewWatchlist(VideoWatchlist $watchlist,?User $viewer):bool{return $watchlist->isPublic()||($viewer instanceof User&&$watchlist->getUser()->getId()!==null&&$watchlist->getUser()->getId()===$viewer->getId());}
    public function canViewClip(VideoClip $clip,?User $viewer):bool{return match($clip->getVisibility()){'public'=>true,'member'=>$viewer instanceof User&&$viewer->isActive()&&!$viewer->isLocked(),'private'=>$viewer instanceof User&&$clip->getCreatedBy()->getId()!==null&&$clip->getCreatedBy()->getId()===$viewer->getId(),default=>false};}
}
