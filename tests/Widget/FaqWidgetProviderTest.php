<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Layout\LayoutDocument;
use App\Layout\LayoutValidator;
use App\Widget\WidgetRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class FaqWidgetProviderTest extends KernelTestCase
{
    public function testRegisteredFaqRendersCompletePairsAsEscapedNativeDisclosures(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $registry = $container->get(WidgetRegistry::class);
        $definition = $registry->get('core.faq');
        self::assertNotNull($definition);
        self::assertSame('core', $definition->module);
        self::assertSame('widget/faq.html.twig', $definition->template);

        $validator = $container->get(LayoutValidator::class);
        $schema = $validator->widgetSchema('core.faq');
        self::assertArrayHasKey('question1', $schema);
        self::assertArrayHasKey('answer4', $schema);
        self::assertSame(180, $schema['question1']['max']);
        self::assertSame(2000, $schema['answer1']['max']);

        $config = [];
        foreach ($schema as $key => $field) {
            $config[$key] = $field['default'];
        }
        $config['question1'] = 'How do I add <img src=x onerror=alert(1)>?';
        $config['answer1'] = 'Use the editor. <script>alert(1)</script>';
        $config['question2'] = 'This question has no answer';
        $config['answer3'] = 'This answer has no question';

        $document = $this->document($validator, $config);
        $widgetConfig = $document->widgets[0]['config'];
        $data = $registry->data('core.faq', $widgetConfig);

        /** @var list<array{question: string, answer: string}> $items */
        $items = $data['items'] ?? [];
        self::assertCount(1, $items);
        self::assertSame('How do I add <img src=x onerror=alert(1)>?', $items[0]['question']);
        self::assertSame('Use the editor. <script>alert(1)</script>', $items[0]['answer']);

        $html = $container->get(Environment::class)->render($definition->template, [
            'config' => $widgetConfig,
            'data' => $data,
        ]);
        self::assertStringContainsString('<details', $html);
        self::assertStringContainsString('<summary>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('This question has no answer', $html);
        self::assertStringNotContainsString('This answer has no question', $html);
    }

    public function testQuestionLengthIsBoundedByTheLayoutValidator(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(LayoutValidator::class);
        $config = $this->defaultConfig($validator);
        $config['question1'] = str_repeat('x', 181);

        $this->expectException(\DomainException::class);
        $this->document($validator, $config);
    }

    public function testAnswerLengthIsBoundedByTheLayoutValidator(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(LayoutValidator::class);
        $config = $this->defaultConfig($validator);
        $config['answer1'] = str_repeat('x', 2001);

        $this->expectException(\DomainException::class);
        $this->document($validator, $config);
    }

    /** @return array<string, string|int|bool> */
    private function defaultConfig(LayoutValidator $validator): array
    {
        $config = [];
        foreach ($validator->widgetSchema('core.faq') as $key => $field) {
            $config[$key] = $field['default'];
        }

        return $config;
    }

    /** @param array<string, string|int|bool> $config */
    private function document(LayoutValidator $validator, array $config): LayoutDocument
    {
        return $validator->validate([
            'schema' => 1,
            'theme' => 'nebula',
            'options' => [],
            'widgets' => [[
                'id' => 'faq-widget-0001',
                'type' => 'core.faq',
                'region' => 'main',
                'enabled' => true,
                'config' => $config,
            ]],
        ]);
    }
}
