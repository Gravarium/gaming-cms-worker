<?php

declare(strict_types=1);
namespace App\Layout;

use App\Entity\MediaAsset;
use App\Module\CmsModuleManager;
use App\Service\MediaUrlPolicy;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LayoutImages
{
    public function __construct(private EntityManagerInterface $em,private CmsModuleManager $modules, private MediaUrlPolicy $mediaUrls) {}
    public function usable(MediaAsset $asset): bool
    {
        $url=$asset->getLocation();
        return !$asset->isDeletionPending() && in_array($asset->getMimeType(),['image/jpeg','image/png','image/webp','image/gif','image/avif'],true)
            && (str_starts_with($url, '/uploads/media/') || str_starts_with($url, 'https://'))
            && $this->mediaUrls->isSafePlayback($url);
    }
    /** @return list<array{id:int,title:string}> */
    public function choices(): array
    {
        $choices=[];
        foreach($this->em->getRepository(MediaAsset::class)->findBy([],['id'=>'DESC'],200) as $asset) if($this->usable($asset)&&$asset->getId()!==null)$choices[]=['id'=>$asset->getId(),'title'=>$asset->getTitle()];
        return $choices;
    }
    /** @return array<int, array{url:string,alt:string}> */
    public function resolve(LayoutDocument $document,bool $strict=false): array
    {
        $ids=[];
        foreach($document->widgets as $widget) {
            $id=$widget['config']['imageId']??0;
            if(is_int($id)&&$id>0)$ids[$id]=$id;
        }
        if($ids===[])return [];
        $images=[];
        foreach($this->em->getRepository(MediaAsset::class)->findBy(['id'=>array_values($ids)]) as $asset) if($this->usable($asset)&&$asset->getId()!==null)$images[$asset->getId()]=['url'=>$asset->getLocation(),'alt'=>$asset->getAltText()??$asset->getTitle()];
        if($strict && count($ids)!==count($images))throw new \DomainException('Ein ausgewähltes Bild ist nicht mehr verfügbar. Bitte ein Bild aus der Mediathek wählen.');
        return $this->modules->isEnabled('media')?$images:[];
    }
}
