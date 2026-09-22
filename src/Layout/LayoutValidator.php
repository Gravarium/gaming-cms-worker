<?php

declare(strict_types=1);
namespace App\Layout;

use App\Theme\ThemeRegistry;
use App\Widget\WidgetRegistry;

final readonly class LayoutValidator
{
    public function __construct(private ThemeRegistry $themes, private WidgetRegistry $widgets) {}

    /** @return array<string, array{label:string,type:string,default:string|int|bool,choices?:list<string>,min?:int,max?:int}> */
    public function optionSchema(): array
    {
        return [
            'accent'=>['label'=>'Akzentfarbe (leer = Theme-Standard)','type'=>'color','default'=>''],
            'font'=>['label'=>'Schriftstil','type'=>'choice','default'=>'theme','choices'=>['theme','sans','serif','mono']],
            'sidebar'=>['label'=>'Sidebar-Breite','type'=>'choice','default'=>'normal','choices'=>['narrow','normal','wide']],
            'overlay'=>['label'=>'Bild-Overlay','type'=>'choice','default'=>'balanced','choices'=>['soft','balanced','strong']],
            'sticky'=>['label'=>'Mitlaufender Bereich','type'=>'choice','default'=>'none','choices'=>['none','top','navigation','header']],
            'width'=>['label'=>'Seitenbreite','type'=>'choice','default'=>'wide','choices'=>['compact','wide','full']],
            'spacing'=>['label'=>'Abstände','type'=>'choice','default'=>'normal','choices'=>['compact','normal','airy']],
            'corners'=>['label'=>'Ecken','type'=>'choice','default'=>'theme','choices'=>['theme','square','rounded']],
            'heroHeight'=>['label'=>'Hero-Höhe','type'=>'choice','default'=>'large','choices'=>['small','large','cinematic']],
            'animations'=>['label'=>'Animationen','type'=>'bool','default'=>true],
        ];
    }
    /** @return array<string, array{label:string,type:string,default:string|int|bool,choices?:list<string>,min?:int,max?:int}> */
    public function widgetSchema(?string $type = null): array
    {
        $common = [
            'title'=>['label'=>'Eigene Überschrift','type'=>'text','default'=>'','max'=>120],
            'text'=>['label'=>'Text (Text-Widget)','type'=>'text','default'=>'','max'=>4000],
            'count'=>['label'=>'Anzahl (Listen)','type'=>'int','default'=>6,'min'=>1,'max'=>12],
            'display'=>['label'=>'Darstellung','type'=>'choice','default'=>'grid','choices'=>['grid','list']],
            'frame'=>['label'=>'Rahmen','type'=>'choice','default'=>'theme','choices'=>['theme','plain','accent']],
            'space'=>['label'=>'Abstand','type'=>'choice','default'=>'normal','choices'=>['compact','normal','airy']],
            'desktop'=>['label'=>'Desktop sichtbar','type'=>'bool','default'=>true],
            'tablet'=>['label'=>'Tablet sichtbar','type'=>'bool','default'=>true],
            'mobile'=>['label'=>'Mobil sichtbar','type'=>'bool','default'=>true],
        ];
        $extra = $type === null ? [] : ($this->widgets->get($type)->settings ?? []);
        if (array_intersect_key($common, $extra) !== []) throw new \LogicException('Widget settings cannot override reserved fields.');
        return $common + $extra;
    }
    /** @param array<string, mixed> $input
     * @param array<string, array{label:string,type:string,default:string|int|bool,choices?:list<string>,min?:int,max?:int}> $schema
     * @return array<string, string|int|bool>
     */
    private function settings(array $input, array $schema): array
    {
        if (array_diff(array_keys($input), array_keys($schema)) !== []) throw new \DomainException('Unbekannte Einstellung.');
        $result = [];
        foreach ($schema as $key=>$field) {
            $value = $input[$key] ?? $field['default'];
            $valid = match ($field['type']) {
                'color'=>is_string($value) && ($value==='' || preg_match('/^#[0-9a-fA-F]{6}$/D',$value)===1),
                'bool'=>is_bool($value),
                'int'=>is_int($value) && $value >= ($field['min'] ?? 0) && $value <= ($field['max'] ?? 100),
                'choice'=>is_string($value) && in_array($value, $field['choices'] ?? [], true),
                'text'=>is_string($value) && mb_strlen($value) <= ($field['max'] ?? 120) && !str_contains($value, "\0"),
                default=>false,
            };
            if (!$valid || (!is_string($value) && !is_int($value) && !is_bool($value))) throw new \DomainException('Ungültige Einstellung: '.$key);
            $result[$key] = $value;
        }
        return $result;
    }
    /** @param array<string, mixed> $input */
    public function validate(array $input, ?LayoutDocument $previous = null, bool $themeChange = false): LayoutDocument
    {
        if (array_diff(array_keys($input), ['schema','theme','options','widgets']) !== [] || ($input['schema'] ?? null) !== 1) throw new \DomainException('Unbekanntes Layout-Format.');
        $themeKey = $input['theme'] ?? null;
        if (!is_string($themeKey) || !$this->themes->has($themeKey)) throw new \DomainException('Unbekanntes Theme.');
        $theme = $this->themes->get($themeKey);
        $options = $input['options'] ?? [];
        $rows = $input['widgets'] ?? null;
        if (!is_array($options) || !is_array($rows) || !array_is_list($rows) || count($rows)>60) throw new \DomainException('Ungültige Layout-Größe.');
        $options = $this->settings($options, $this->optionSchema());
        $old = []; foreach ($previous->widgets ?? [] as $row) $old[$row['id']] = $row;
        $widgets = []; $ids = []; $counts = []; $notices = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['id','type','region','enabled','config']) !== []) throw new \DomainException('Ungültiges Widget.');
            $id=$row['id']??null; $key=$row['type']??null; $region=$row['region']??null; $enabled=$row['enabled']??null; $config=$row['config']??null;
            if (!is_string($id) || preg_match('/^[a-z0-9-]{8,64}$/D',$id)!==1 || isset($ids[$id]) || !is_string($key) || !is_string($region) || !is_bool($enabled) || !is_array($config)) throw new \DomainException('Ungültige Widget-Instanz.');
            $ids[$id]=true;
            $definition=$this->widgets->get($key);
            if (!$this->widgets->available($key) && (!isset($old[$id]) || $old[$id]['type']!==$key || $old[$id]['config']!==$config || ($enabled && !$old[$id]['enabled']))) throw new \DomainException('Nicht verfügbares Widget kann nicht neu angelegt oder verändert werden.');
            if ($definition!==null && !$definition->multiple && isset($counts[$key])) throw new \DomainException('Dieses Widget ist nur einmal pro Seite erlaubt.');
            $counts[$key]=true;
            $compatible = static fn (string $r): bool => in_array($r, $theme->regions, true) && ($definition===null || $definition->regions===[] || in_array($r,$definition->regions,true));
            if (!$compatible($region)) {
                if (!$themeChange || !isset($old[$id]) || $old[$id]['region']!==$region) throw new \DomainException('Widget passt nicht in diese Region.');
                $region='';
                foreach ([$theme->fallbackRegion, ...$theme->regions] as $candidate) if ($compatible($candidate)) { $region=$candidate; break; }
                if ($region==='') { $region=$theme->fallbackRegion; $enabled=false; }
                $notices[]='Widget '.$id.' nach '.$region.' verschoben'.($enabled?'': ' (deaktiviert)').'.';
            }
            $widgets[]=['id'=>$id,'type'=>$key,'region'=>$region,'enabled'=>$enabled,'config'=>$definition===null && isset($old[$id]) ? $old[$id]['config'] : $this->settings($config,$this->widgetSchema($key))];
        }
        return new LayoutDocument($themeKey,$options,$widgets,$notices);
    }
    public function defaults(string $theme): LayoutDocument
    {
        return $this->validate(['schema'=>1,'theme'=>$this->themes->get($theme)->key,'options'=>[], 'widgets'=>[
            ['id'=>'welcome-home','type'=>'core.welcome','region'=>'hero','enabled'=>true,'config'=>[]],
        ]]);
    }
}
