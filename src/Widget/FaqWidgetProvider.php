<?php

declare(strict_types=1);

namespace App\Widget;

final class FaqWidgetProvider implements WidgetProvider
{
    private const int MAX_ITEMS = 4;

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                'core.faq',
                'Häufige Fragen',
                'core',
                'widget/faq.html.twig',
                [],
                true,
                self::settings(),
            ),
        ];
    }

    /** @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== 'core.faq') {
            return [];
        }

        /** @var list<array{question: string, answer: string}> $items */
        $items = [];
        for ($index = 1; $index <= self::MAX_ITEMS; ++$index) {
            $question = $config['question'.$index] ?? null;
            $answer = $config['answer'.$index] ?? null;
            if (!is_string($question) || !is_string($answer)) {
                continue;
            }

            $question = trim($question);
            $answer = trim($answer);
            if ($question === '' || $answer === '') {
                continue;
            }

            $items[] = ['question' => $question, 'answer' => $answer];
        }

        return ['items' => $items];
    }

    /**
     * @return array<string, array{label: string, type: 'text', default: string, max: int}>
     */
    private static function settings(): array
    {
        $settings = [];
        for ($index = 1; $index <= self::MAX_ITEMS; ++$index) {
            $settings['question'.$index] = [
                'label' => 'Frage '.$index,
                'type' => 'text',
                'default' => '',
                'max' => 180,
            ];
            $settings['answer'.$index] = [
                'label' => 'Antwort '.$index,
                'type' => 'text',
                'default' => '',
                'max' => 2000,
            ];
        }

        return $settings;
    }
}
