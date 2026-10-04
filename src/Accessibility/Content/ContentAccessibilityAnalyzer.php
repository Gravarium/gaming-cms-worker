<?php

declare(strict_types=1);

namespace App\Accessibility\Content;

use App\ContentEditor\ContentBlockDocument;

final readonly class ContentAccessibilityAnalyzer
{
    public function __construct(private ContentBlockDocument $documents)
    {
    }

    /**
     * @return array{missing_media_alt:int,heading_order:int,invalid_document:int}
     */
    public function analyze(string $document): array
    {
        $counts = [
            'missing_media_alt' => 0,
            'heading_order' => 0,
            'invalid_document' => 0,
        ];

        try {
            $blocks = $this->documents->decode($document)['blocks'];
        } catch (\InvalidArgumentException) {
            $counts['invalid_document'] = 1;

            return $counts;
        }

        // The page title supplies the h1, so body headings should begin at h2.
        $previousHeadingLevel = 1;

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'media') {
                $alt = $block['alt'] ?? null;
                if (!is_string($alt) || trim($alt) === '') {
                    ++$counts['missing_media_alt'];
                }
            }

            if (($block['type'] ?? null) !== 'heading') {
                continue;
            }

            $level = $block['level'] ?? null;
            if (!is_int($level)) {
                continue;
            }

            if ($level > $previousHeadingLevel + 1) {
                ++$counts['heading_order'];
            }

            $previousHeadingLevel = $level;
        }

        return $counts;
    }
}
