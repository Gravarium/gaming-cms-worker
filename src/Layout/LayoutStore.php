<?php

declare(strict_types=1);
namespace App\Layout;

use App\Entity\ContentEntry;
use App\Entity\PageLayout;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LayoutStore
{
    private const MAX_PREVIOUS_WIDGETS = 60;
    private const MAX_PREVIOUS_ROW_KEYS = 5;
    private const MAX_PREVIOUS_CONFIG_KEYS = 64;
    private const MAX_PREVIOUS_KEY_BYTES = 128;
    private const MAX_PREVIOUS_VALUE_BYTES = 16000;
    private const MAX_PREVIOUS_CONFIG_BYTES = 2000000;

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
        try {
            $previous=$this->previousWidgets($raw);
            return $this->validator->validate($raw,new LayoutDocument('',[],$previous));
        } catch (\DomainException) {
            return $this->validator->defaults($this->settings->current()->getThemeKey());
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array{id:string,type:string,region:string,enabled:bool,config:array<string,string|int|bool>}>
     */
    private function previousWidgets(array $raw): array
    {
        if (count($raw) > 4) {
            throw new \DomainException('Stored layout has too many root fields.');
        }
        foreach (array_keys($raw) as $key) {
            if (!is_string($key) || strlen($key) > self::MAX_PREVIOUS_KEY_BYTES) {
                throw new \DomainException('Stored layout contains an oversized root key.');
            }
        }

        $rows=$raw['widgets']??[];
        if (!is_array($rows) || count($rows) > self::MAX_PREVIOUS_WIDGETS || !array_is_list($rows)) {
            throw new \DomainException('Stored layout has an invalid widget collection.');
        }

        $previous=[];
        $totalConfigBytes=0;
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) > self::MAX_PREVIOUS_ROW_KEYS) {
                throw new \DomainException('Stored layout has an invalid widget row.');
            }
            foreach ($row as $key => $_value) {
                if (!is_string($key) || strlen($key) > self::MAX_PREVIOUS_KEY_BYTES) {
                    throw new \DomainException('Stored layout contains an oversized widget key.');
                }
            }

            $id=$row['id']??null;
            $type=$row['type']??null;
            $region=$row['region']??null;
            $enabled=$row['enabled']??null;
            $sourceConfig=$row['config']??null;
            if (
                !is_string($id) || strlen($id) > 64
                || !is_string($type) || strlen($type) > self::MAX_PREVIOUS_KEY_BYTES
                || !is_string($region) || strlen($region) > self::MAX_PREVIOUS_KEY_BYTES
                || !is_bool($enabled)
                || !is_array($sourceConfig) || count($sourceConfig) > self::MAX_PREVIOUS_CONFIG_KEYS
            ) {
                throw new \DomainException('Stored layout contains an invalid widget configuration.');
            }

            $config=[];
            foreach ($sourceConfig as $key => $value) {
                if (
                    !is_string($key) || strlen($key) > self::MAX_PREVIOUS_KEY_BYTES
                    || (!is_string($value) && !is_int($value) && !is_bool($value))
                ) {
                    throw new \DomainException('Stored layout contains an invalid configuration value.');
                }

                $entryBytes=strlen($key);
                if (is_string($value)) {
                    $valueBytes=strlen($value);
                    if ($valueBytes > self::MAX_PREVIOUS_VALUE_BYTES) {
                        throw new \DomainException('Stored layout contains an oversized configuration value.');
                    }
                    $entryBytes += $valueBytes;
                } else {
                    $entryBytes += 16;
                }

                $totalConfigBytes += $entryBytes;
                if ($totalConfigBytes > self::MAX_PREVIOUS_CONFIG_BYTES) {
                    throw new \DomainException('Stored layout configuration exceeds the aggregate byte limit.');
                }
                $config[$key]=$value;
            }

            $previous[]=['id'=>$id,'type'=>$type,'region'=>$region,'enabled'=>$enabled,'config'=>$config];
        }

        return $previous;
    }
}
