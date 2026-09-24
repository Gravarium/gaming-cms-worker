<?php
declare(strict_types=1);
namespace App\Video\Discovery;
use App\Repository\CmsModuleStateRepository;
final readonly class VideoModuleAvailability
{
    public function __construct(private CmsModuleStateRepository $states){}
    public function enabled():bool{return $this->states->find('video')?->isEnabled()??true;}
}
