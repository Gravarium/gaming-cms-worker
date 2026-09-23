<?php
declare(strict_types=1);
namespace App\ContentEditor;
interface OwnedMediaReferenceGateway{/** @return array{id:int,url:string,title:string,mime:string}|null */ public function resolve(int $assetId): ?array;}
