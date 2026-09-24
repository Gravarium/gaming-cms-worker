<?php

declare(strict_types=1);

namespace App\Tests\ContentEditor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ContentEditorJavaScriptProtocolTest extends TestCase
{
    public function testBrowserControllerPreservesVersionedProtocolAndLegacyFallback(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $path = $projectDir.'/assets/controllers/content_editor_controller.js';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString("const PREFIX='cms-blocks:v1\\n';", $source);

        $source = str_replace(
            "import { Controller } from '@hotwired/stimulus';",
            'class Controller {}',
            $source,
        );
        $source = str_replace(
            'export default class extends Controller',
            'class ContentEditorController extends Controller',
            $source,
        );

        $script = $source.<<<'JS'

const controller = new ContentEditorController();
const legacy = controller.parse("Alpha\n\nBeta <script>");
controller.document = legacy;
controller.sourceTarget = { value: '' };
controller.syncSource();
const roundTrip = controller.parse(controller.sourceTarget.value);
console.log(JSON.stringify({
    prefix: controller.sourceTarget.value.slice(0, 'cms-blocks:v1\n'.length),
    types: roundTrip.blocks.map(block => block.type),
    texts: roundTrip.blocks.map(block => block.text),
    version: roundTrip.version
}));
JS;

        $process = new Process(['node', '--input-type=module', '-e', $script], $projectDir);
        $process->setTimeout(10);
        $process->mustRun();

        $result = json_decode(trim($process->getOutput()), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame("cms-blocks:v1\n", $result['prefix']);
        self::assertSame(1, $result['version']);
        self::assertSame(['text', 'text'], $result['types']);
        self::assertSame(['Alpha', 'Beta <script>'], $result['texts']);
    }
}
