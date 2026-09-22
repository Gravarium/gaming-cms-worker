<?php

declare(strict_types=1);
namespace App\Tests\Layout;

use App\Layout\LayoutValidator;
use App\Layout\LayoutRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LayoutContractTest extends KernelTestCase
{
    private function validator(): LayoutValidator { self::bootKernel(); return self::getContainer()->get(LayoutValidator::class); }
    public function testUnknownWidgetAndInjectedConfigurationAreRejected(): void
    {
        $validator=$this->validator();
        $doc=$validator->defaults('nebula')->toArray();
        $doc['widgets'][0]['type']='../../private/template';
        $this->expectException(\DomainException::class);$validator->validate($doc);
    }
    public function testUnknownConfigCannotBecomeCssOrTemplate(): void
    {
        $validator=$this->validator();$doc=$validator->defaults('nebula')->toArray();$doc['widgets'][0]['config']['template']='private/file';
        $this->expectException(\DomainException::class);$validator->validate($doc);
    }
    public function testInvalidRegionIsRejectedWithoutThemeChange(): void
    {
        $validator=$this->validator();$doc=$validator->defaults('nebula')->toArray();$doc['widgets'][0]['region']='not-a-region';
        $this->expectException(\DomainException::class);$validator->validate($doc);
    }
    public function testThemeSwitchPreservesInstancesAndMapsMissingRegion(): void
    {
        $validator=$this->validator();$doc=$validator->defaults('nebula')->toArray();$doc['widgets'][0]['region']='left-sidebar';$old=$validator->validate($doc);
        $doc['theme']='gravarium-cinematic';$new=$validator->validate($doc,$old,true);
        self::assertSame('content',$new->widgets[0]['region']);self::assertSame($old->widgets[0]['id'],$new->widgets[0]['id']);self::assertNotEmpty($new->notices);
    }
    public function testOrderAndDisabledWidgetsArePreservedButNotRendered(): void
    {
        $validator=$this->validator();$doc=$validator->defaults('nebula')->toArray();$doc['widgets'][0]['enabled']=false;
        $new=$validator->validate($doc);self::assertFalse($new->widgets[0]['enabled']);
        $view=self::getContainer()->get(LayoutRenderer::class)->view($new);self::assertSame([], $view['regions']['hero']);
    }
    public function testUnknownStickyModeIsRejected(): void
    {
        $validator=$this->validator();$doc=$validator->defaults('nebula')->toArray();$doc['options']['sticky']='javascript:alert(1)';
        $this->expectException(\DomainException::class);$validator->validate($doc);
    }
    public function testDuplicateIdsAndOversizedListsAreRejected(): void
    {
        $validator=$this->validator();$doc=$validator->defaults('nebula')->toArray();$doc['widgets'][]=$doc['widgets'][0];
        $this->expectException(\DomainException::class);$validator->validate($doc);
    }
    public function testMaliciousColorAndOversizedWidgetSetAreRejected(): void
    {
        $validator=$this->validator();$doc=$validator->defaults('nebula')->toArray();$doc['options']['accent']='red; background:url(javascript:alert(1))';
        try { $validator->validate($doc);self::fail('CSS injection accepted'); } catch (\DomainException) {}
        $doc=$validator->defaults('nebula')->toArray();$doc['widgets']=array_fill(0,61,$doc['widgets'][0]);
        $this->expectException(\DomainException::class);$validator->validate($doc);
    }
    public function testIndependentInstancesKeepExplicitOrder(): void
    {
        $validator=$this->validator();$doc=$validator->defaults('nebula')->toArray();
        $doc['widgets']=[['id'=>'text-second','type'=>'core.text','region'=>'main','enabled'=>true,'config'=>['text'=>'Second']],['id'=>'text-first','type'=>'core.text','region'=>'main','enabled'=>true,'config'=>['text'=>'First']]];
        self::assertSame(['text-second','text-first'],array_column($validator->validate($doc)->widgets,'id'));
    }
}
