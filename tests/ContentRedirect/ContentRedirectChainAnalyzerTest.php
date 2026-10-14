<?php

declare(strict_types=1);

namespace App\Tests\ContentRedirect;

use App\ContentRedirect\ContentRedirectChainAnalyzer;
use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentRedirectChainAnalyzerTest extends TestCase
{
    public function testDirectRedirectsAreCleanAndTypesStayIndependent(): void
    {
        $findings = (new ContentRedirectChainAnalyzer())->analyze([
            $this->redirect('page', 'old-page', 'current-page'),
            $this->redirect('news', 'old-news', 'current-news'),
        ]);

        self::assertSame([], $findings);
    }

    public function testFindsDeterministicMultiHopChain(): void
    {
        $findings = (new ContentRedirectChainAnalyzer())->analyze([
            $this->redirect('page', 'second-old', 'current'),
            $this->redirect('page', 'first-old', 'second-old'),
        ]);

        self::assertSame([[
            'type' => 'page',
            'source_slug' => 'first-old',
            'path' => ['first-old', 'second-old', 'current'],
            'cycle' => false,
        ]], $findings);
    }

    public function testFindsCycleAndReportsAffectedStartsDeterministically(): void
    {
        $findings = (new ContentRedirectChainAnalyzer())->analyze([
            $this->redirect('news', 'beta', 'alpha'),
            $this->redirect('news', 'alpha', 'beta'),
        ]);

        self::assertCount(2, $findings);
        self::assertSame(['alpha', 'beta'], array_column($findings, 'source_slug'));
        self::assertSame(['alpha', 'beta', 'alpha'], $findings[0]['path']);
        self::assertTrue($findings[0]['cycle']);
        self::assertSame(['beta', 'alpha', 'beta'], $findings[1]['path']);
        self::assertTrue($findings[1]['cycle']);
    }

    public function testConflictingDuplicateSourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ContentRedirectChainAnalyzer())->analyze([
            $this->redirect('page', 'old', 'target-one'),
            $this->redirect('page', 'old', 'target-two'),
        ]);
    }

    private function redirect(string $type, string $source, string $target): ContentRedirect
    {
        $entry = (new ContentEntry())->setType($type)->setTitle($target)->setSlug($target);

        return new ContentRedirect($entry, $type, $source);
    }
}
