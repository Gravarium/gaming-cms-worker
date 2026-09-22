<?php

declare(strict_types=1);
namespace App\Tests\Layout;

use App\Entity\ContentEntry;
use App\Layout\LayoutRenderer;
use App\Layout\LayoutValidator;
use App\Theme\ThemeRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;
use Symfony\Component\HttpFoundation\RequestStack;

final class LayoutVisualPreviewTest extends WebTestCase
{
    public function testAllRegisteredThemesRenderWithSyntheticVisualFixtures(): void
    {
        $client=self::createClient();$client->request('GET','/');
        $container=self::getContainer();$validator=$container->get(LayoutValidator::class);$renderer=$container->get(LayoutRenderer::class);$twig=$container->get(Environment::class);
        $dir=dirname(__DIR__,2).'/var/layout-preview';if(!is_dir($dir))mkdir($dir,0770,true);
        $entries=[];
        foreach(['Gemeinsam beginnt das nächste Kapitel','Ein Platz für deine Geschichten','Entdecke die Welt deiner Community','Neue Abenteuer warten auf dich'] as $i=>$title) $entries[]=(new ContentEntry())->setType('news')->setTitle($title)->setSlug('visual-fixture-'.$i)->setExcerpt('Synthetischer Inhalt für die Designprüfung. Auf dem Server erscheinen hier deine veröffentlichten Beiträge.')->setPublishedAt(new \DateTimeImmutable('2026-09-22 12:00:00'));
        $requests=$container->get(RequestStack::class);$requests->push($client->getRequest());
        try {
        foreach($container->get(ThemeRegistry::class)->choices() as $key) {
            $doc=$validator->defaults($key)->toArray();
            $doc['widgets'][0]['config']['title']='Eine Welt, die mit dir wächst.';
            $doc['widgets'][0]['config']['text']='Deine Spiele. Deine Gilden. Deine Geschichten. Entdecke einen Ort, der deine Community zusammenbringt.';
            $main=$key==='gravarium-cinematic'?'content':'main';$left=$key==='gravarium-cinematic'?'sidebar':'left-sidebar';
            foreach([['content.news',$main,'Im Fokus'],['core.text',$left,'Deine Community'],['core.text',$key==='gravarium-cinematic'?'content-wide-1':'right-sidebar','Gemeinsam mehr erleben']] as $i=>[$type,$region,$title]) $doc['widgets'][]=['id'=>'visual-fixture-'.$i,'type'=>$type,'region'=>$region,'enabled'=>true,'config'=>['title'=>$title,'text'=>'Synthetische Vorschau. Hier finden deine Widgets ihren Platz. Frei verschiebbar und pro Seite konfigurierbar.']];
            $portal=$renderer->view($validator->validate($doc));
            foreach($portal['regions'] as &$rows) foreach($rows as &$row) if($row['definition']->key==='content.news')$row['data']['items']=$entries;
            unset($rows,$row);
            $html=$twig->render('layout/preview.html.twig',['portal'=>$portal,'entry'=>null]);
            self::assertStringContainsString('theme-'.$key,$html);self::assertStringNotContainsString('images.unsplash.com',$html);
            file_put_contents($dir.'/'.$key.'.html',$html);
        }
        } finally { $requests->pop(); }
        self::assertFileExists($dir.'/gravarium-cyberpunk.html');
    }
}
