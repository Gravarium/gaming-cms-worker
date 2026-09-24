<?php
declare(strict_types=1);
namespace App\ContentEditor;
use App\Entity\MediaAsset;use App\Repository\MediaAssetRepository;use App\Service\MediaUrlPolicy;
final readonly class DoctrineOwnedMediaReferenceGateway implements OwnedMediaReferenceGateway
{
    private const IMAGE_MIME_TYPES=['image/jpeg','image/png','image/webp','image/gif','image/avif'];
    public function __construct(private MediaAssetRepository $assets,private MediaUrlPolicy $urlPolicy){}
    public function resolve(int $assetId): ?array
    {
        if($assetId<1)return null;$asset=$this->assets->find($assetId);
        if(!$asset instanceof MediaAsset||$asset->getModuleKey()!=='content'||$asset->isDeletionPending()||!in_array((string)$asset->getMimeType(),self::IMAGE_MIME_TYPES,true))return null;
        $url=trim($asset->getLocation());if((!str_starts_with($url,'/uploads/media/')&&!str_starts_with($url,'https://'))||!$this->urlPolicy->isSafePlayback($url))return null;
        return ['id'=>$assetId,'url'=>$url,'title'=>$asset->getTitle(),'mime'=>(string)$asset->getMimeType()];
    }
}
