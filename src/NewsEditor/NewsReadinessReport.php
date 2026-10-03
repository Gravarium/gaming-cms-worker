<?php

declare(strict_types=1);

namespace App\NewsEditor;

use App\Entity\ContentEntry;

/**
 * Builds a deterministic, advisory report without returning article text.
 * The normalized document is validated again at this boundary so callers
 * cannot accidentally analyze an untrusted shape.
 */
final readonly class NewsReadinessReport
{
    private const MAX_FINDINGS = 80;

    public function __construct(private RichDocument $documents)
    {
    }

    /** @return array{summary:array<string,mixed>,metrics:array<string,int|float>,findings:list<array{code:string,category:string,severity:string,blockIndex:?int,message:string}>} */
    public function analyze(string $normalizedDocument, ContentEntry $entry): array
    {
        $blocks = $this->documents->decode($normalizedDocument)['blocks'];
        $findings = [];
        $truncated = false;
        $wordCount = 0;
        $linkCount = 0;
        $linkedWords = 0;
        $imageCount = 0;
        $headingCount = 0;
        $h2Count = 0;
        $previousHeadingLevel = null;
        $readableParts = [];

        foreach ($blocks as $index => $block) {
            $type = $block['type'];
            $text = $this->readableText($block);
            $blockWords = $this->countWords($text);
            $wordCount += $blockWords;
            if ($text !== '') {
                $readableParts[] = $text;
            }

            [$blockLinks, $blockLinkedWords, $hasUndescriptiveLink] = $this->linkStats($block);
            $linkCount += $blockLinks;
            $linkedWords += $blockLinkedWords;
            if ($hasUndescriptiveLink) {
                $this->addFinding($findings, $truncated, 'links.descriptive_text', 'links', 'warning', $index, 'Mindestens ein Linktext ist leer, nennt nur die URL oder gibt das Linkziel nicht klar an.');
            }

            if (in_array($type, ['paragraph', 'heading', 'callout'], true) && trim($text) === '') {
                $this->addFinding($findings, $truncated, 'content.empty_block', 'structure', 'warning', $index, 'Dieser Textblock ist leer.');
            }
            if ($blockWords > 250) {
                $this->addFinding($findings, $truncated, 'content.block_long', 'readability', 'info', $index, 'Dieser Abschnitt ist sehr lang und könnte leichter lesbar sein, wenn du ihn teilst.');
            }

            if ($type === 'heading') {
                ++$headingCount;
                $level = (int) $block['level'];
                if ($level === 2) {
                    ++$h2Count;
                }
                if ($previousHeadingLevel === null && $level !== 2) {
                    $this->addFinding($findings, $truncated, 'structure.first_heading_level', 'structure', 'warning', $index, 'Die erste Abschnittsüberschrift sollte eine H2-Überschrift sein.');
                } elseif ($previousHeadingLevel !== null && $level > $previousHeadingLevel + 1) {
                    $this->addFinding($findings, $truncated, 'structure.heading_level_jump', 'structure', 'warning', $index, 'Die Überschriftenebene überspringt eine Stufe.');
                }
                $previousHeadingLevel = $level;
            }

            if ($type === 'media') {
                ++$imageCount;
                if (trim($block['alt']) === '') {
                    $this->addFinding($findings, $truncated, 'accessibility.image_alt_missing', 'accessibility', 'warning', $index, 'Prüfe, ob das Bild einen Alternativtext braucht oder bewusst dekorativ ist.');
                } elseif (mb_strlen(trim($block['alt'])) > 125) {
                    $this->addFinding($findings, $truncated, 'accessibility.image_alt_long', 'accessibility', 'info', $index, 'Der Alternativtext ist lang; eine knappe Bildbeschreibung ist meist hilfreicher.');
                }
            }

            if ($type === 'quote' && trim($block['cite']) === '') {
                $this->addFinding($findings, $truncated, 'attribution.quote_missing', 'attribution', 'warning', $index, 'Für dieses Zitat ist keine Quelle oder sprechende Person angegeben.');
            }
        }

        if ($h2Count === 0) {
            $this->addFinding($findings, $truncated, 'structure.h2_missing', 'structure', 'warning', null, 'Eine H2-Überschrift kann den Artikel in Abschnitte gliedern.');
        }

        $bodyText = trim(implode(' ', $readableParts));
        if ($wordCount === 0) {
            $this->addFinding($findings, $truncated, 'content.no_readable_text', 'readability', 'warning', null, 'Der Artikel enthält noch keinen lesbaren Fließtext.');
        }

        $sentenceMatches = [];
        $sentenceCount = preg_match_all('/[.!?…]+(?=\s|$)/u', $bodyText, $sentenceMatches);
        $sentenceCount = max(1, (int) $sentenceCount);
        $averageSentenceWords = $wordCount > 0 ? round($wordCount / $sentenceCount, 1) : 0.0;
        if ($wordCount >= 40 && $averageSentenceWords > 35) {
            $this->addFinding($findings, $truncated, 'readability.sentences_long', 'readability', 'info', null, 'Die Sätze sind im Durchschnitt lang; kürzere Sätze können das Lesen erleichtern.');
        }
        if ($wordCount > 5000) {
            $this->addFinding($findings, $truncated, 'readability.article_long', 'readability', 'info', null, 'Der Artikel ist sehr umfangreich; prüfe, ob sich der Inhalt auf mehrere Beiträge verteilen lässt.');
        }
        $linkDensityPercent = $wordCount > 0 ? round(($linkedWords / $wordCount) * 100, 1) : 0.0;
        if ($wordCount >= 30 && $linkDensityPercent > 25) {
            $this->addFinding($findings, $truncated, 'links.density_high', 'links', 'info', null, 'Ein großer Teil des Textes ist verlinkt; prüfe, ob alle Links nötig und verständlich beschriftet sind.');
        }

        $title = trim($entry->getSeoTitle() ?? '') ?: trim($entry->getTitle());
        if ($title === '') {
            $this->addFinding($findings, $truncated, 'metadata.title_missing', 'metadata', 'warning', null, 'Für den Artikel fehlt ein Titel.');
        } elseif (mb_strlen($title) > 70) {
            $this->addFinding($findings, $truncated, 'metadata.title_long', 'metadata', 'info', null, 'Der Titel ist lang; prüfe, ob er in Suchergebnissen vollständig erscheint.');
        }

        $description = trim($entry->getSeoDescription() ?? '') ?: trim($entry->getExcerpt() ?? '');
        if ($description === '') {
            $this->addFinding($findings, $truncated, 'metadata.description_missing', 'metadata', 'info', null, 'Eine Kurzbeschreibung hilft bei Vorschau und Suchdarstellung.');
        } elseif (mb_strlen($description) > 160) {
            $this->addFinding($findings, $truncated, 'metadata.description_long', 'metadata', 'info', null, 'Die Kurzbeschreibung ist lang; eine kompakte Fassung passt besser in viele Vorschauen.');
        }
        if (trim($entry->getSlug()) === '') {
            $this->addFinding($findings, $truncated, 'metadata.slug_missing', 'metadata', 'warning', null, 'Für den Artikel fehlt eine URL-Kennung.');
        }
        if ($entry->getCategory() === null) {
            $this->addFinding($findings, $truncated, 'metadata.category_missing', 'metadata', 'info', null, 'Dem Artikel ist noch keine Kategorie zugeordnet.');
        }

        if ($truncated) {
            $findings[] = [
                'code' => 'report.findings_limited',
                'category' => 'general',
                'severity' => 'info',
                'blockIndex' => null,
                'message' => 'Es werden nur die ersten 79 Hinweise angezeigt.',
            ];
        }

        $warningCount = count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning'));
        $infoCount = count($findings) - $warningCount;

        return [
            'summary' => [
                'state' => $warningCount > 0 ? 'review_recommended' : 'no_major_issues',
                'message' => $warningCount > 0
                    ? 'Bitte prüfe die Hinweise. Dieses Audit ist unverbindlich und entscheidet nicht über die Veröffentlichung.'
                    : 'Keine größeren Hinweise gefunden. Dieses Audit entscheidet nicht über die Veröffentlichung.',
                'findingCount' => count($findings),
                'warningCount' => $warningCount,
                'infoCount' => $infoCount,
                'limited' => $truncated,
            ],
            'metrics' => [
                'wordCount' => $wordCount,
                'blockCount' => count($blocks),
                'headingCount' => $headingCount,
                'imageCount' => $imageCount,
                'linkCount' => $linkCount,
                'readingMinutes' => max(1, (int) ceil($wordCount / 200)),
                'averageSentenceWords' => $averageSentenceWords,
                'linkDensityPercent' => $linkDensityPercent,
            ],
            'findings' => $findings,
        ];
    }

    /** @param array<string,mixed> $block */
    private function readableText(array $block): string
    {
        $text = match ($block['type']) {
            'paragraph', 'heading', 'quote', 'callout' => $this->runsText($block['content']),
            'list' => implode(' ', array_map(fn (array $item): string => $this->runsText($item), $block['items'])),
            'table' => implode(' ', array_map(fn (array $row): string => implode(' ', array_map(fn (array $cell): string => $this->runsText($cell), $row)), $block['rows'])),
            'media', 'video' => (string) ($block['caption'] ?? ''),
            default => '',
        };

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<string,mixed> $block
     * @return array{0:int,1:int,2:bool}
     */
    private function linkStats(array $block): array
    {
        /** @var list<list<array<string, mixed>>> $groups */
        $groups = [];
        if (isset($block['content'])) {
            $groups[] = $block['content'];
        }
        if (($block['type'] ?? null) === 'list') {
            array_push($groups, ...$block['items']);
        } elseif (($block['type'] ?? null) === 'table') {
            foreach ($block['rows'] as $row) {
                array_push($groups, ...$row);
            }
        }

        $links = 0;
        $linkedWords = 0;
        $hasUndescriptiveLink = false;
        foreach ($groups as $runs) {
            $currentHref = null;
            $currentLabel = '';
            foreach ($runs as $run) {
                $isLink = in_array('link', $run['marks'], true);
                if ($isLink) {
                    $href = $run['href'] ?? '';
                    if ($currentHref !== null && $href !== $currentHref) {
                        $hasUndescriptiveLink = $hasUndescriptiveLink || !$this->hasDescriptiveLinkText($currentLabel);
                        $currentHref = null;
                        $currentLabel = '';
                    }
                    if ($currentHref === null) {
                        ++$links;
                        $currentHref = $href;
                    }
                    $currentLabel .= $run['text'];
                    $linkedWords += $this->countWords($run['text']);
                } elseif ($currentHref !== null) {
                    $hasUndescriptiveLink = $hasUndescriptiveLink || !$this->hasDescriptiveLinkText($currentLabel);
                    $currentHref = null;
                    $currentLabel = '';
                }
            }
            if ($currentHref !== null) {
                $hasUndescriptiveLink = $hasUndescriptiveLink || !$this->hasDescriptiveLinkText($currentLabel);
            }
        }

        return [$links, $linkedWords, $hasUndescriptiveLink];
    }

    private function hasDescriptiveLinkText(string $label): bool
    {
        $label = mb_strtolower(trim((string) preg_replace('/\\s+/u', ' ', $label)));
        if ($label === '' || preg_match('/\\A(?:https?:\\/\\/|www\\.)\\S+\\z/u', $label) === 1) {
            return false;
        }

        $normalized = (string) preg_replace('/\\A[\\p{P}\\p{S}\\s]+|[\\p{P}\\p{S}\\s]+\\z/u', '', $label);

        return !in_array($normalized, [
            'hier', 'hier klicken', 'klick hier', 'mehr', 'weiter', 'weiterlesen',
            'zum artikel', 'zum beitrag', 'link', 'website', 'webseite', 'mehr erfahren',
        ], true);
    }

    /** @param array<int, array<string, mixed>> $runs */
    private function runsText(array $runs): string
    {
        return implode('', array_map(static fn (array $run): string => (string) ($run['text'] ?? ''), $runs));
    }

    private function countWords(string $text): int
    {
        $matches = [];
        $count = preg_match_all('/[\p{L}\p{N}]+(?:[\'’][\p{L}\p{N}]+)*/u', $text, $matches);

        return max(0, (int) $count);
    }

    /** @param list<array{code:string,category:string,severity:string,blockIndex:?int,message:string}> $findings */
    private function addFinding(array &$findings, bool &$truncated, string $code, string $category, string $severity, ?int $blockIndex, string $message): void
    {
        if (count($findings) >= self::MAX_FINDINGS - 1) {
            $truncated = true;
            return;
        }

        $findings[] = [
            'code' => $code,
            'category' => $category,
            'severity' => $severity,
            'blockIndex' => $blockIndex,
            'message' => $message,
        ];
    }
}
