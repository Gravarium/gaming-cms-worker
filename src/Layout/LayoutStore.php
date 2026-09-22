<?php

declare(strict_types=1);
namespace App\Layout;

use App\Entity\ContentEntry;
use App\Entity\PageLayout;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LayoutStore
{
    public function __construct(private EntityManagerInterface $em, private LayoutValidator $validator, private SiteSettingsRepository $settings) {}
    public function exists(string $context): bool
    {
        if ($context==='home') return true;
        if (preg_match('/^page-([1-9][0-9]{0,9})$/D',$context,$match)!==1) return false;
        $entry=$this->em->find(ContentEntry::class,(int)$match[1]);
        return $entry instanceof ContentEntry && $entry->getType()===ContentEntry::TYPE_PAGE;
    }
    public function record(string $context): ?PageLayout { return $this->em->find(PageLayout::class,$context); }
    public function load(string $context): LayoutDocument
    {
        $record=$this->record($context);
        if ($record===null) {
            $default=$this->validator->defaults($this->settings->current()->getThemeKey());
            return $context==='home'?$default:new LayoutDocument($default->theme,$default->options,[]);
        }
        // Stored unavailable widgets remain dormant. Their configuration is retained as data.
        $raw=$record->getDocument();
        $rows=$raw['widgets']??[];
        $previous=[];
        if (is_array($rows)) foreach($rows as $row) {
            if (is_array($row) && is_string($row['id']??null) && is_string($row['type']??null) && is_string($row['region']??null) && is_bool($row['enabled']??null) && is_array($row['config']??null)) {
                $config=[];
                foreach($row['config'] as $k=>$v) if(is_string($k) && (is_string($v)||is_int($v)||is_bool($v))) $config[$k]=$v;
                $previous[]=['id'=>$row['id'],'type'=>$row['type'],'region'=>$row['region'],'enabled'=>$row['enabled'],'config'=>$config];
            }
        }
        try { return $this->validator->validate($raw,new LayoutDocument('',[],$previous)); }
        catch (\DomainException) { return $this->validator->defaults($this->settings->current()->getThemeKey()); }
    }
}
