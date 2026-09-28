<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminVideoController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class AdminVideoControllerSlugBoundaryTest extends TestCase
{
    public function testGeneratedSlugFitsItsColumnWhenTheSluggerExpandsTheTitle(): void
    {
        foreach ([140, 180, 200] as $maximum) {
            $slug = $this->generateSlug(str_repeat('Video Title ', 40), $maximum, static fn (string $candidate, ?int $id): bool => false);

            self::assertLessThanOrEqual($maximum, strlen($slug));
            self::assertMatchesRegularExpression('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug);
        }
    }

    public function testCollisionSuffixKeepsTheGeneratedSlugWithinItsColumn(): void
    {
        $calls = 0;
        $exists = static function (string $candidate, ?int $id) use (&$calls): bool {
            ++$calls;

            return $calls === 1;
        };

        $slug = $this->generateSlug(str_repeat('Video Title ', 40), 140, $exists);

        self::assertSame(2, $calls);
        self::assertLessThanOrEqual(140, strlen($slug));
        self::assertStringEndsWith('-2', $slug);
        self::assertMatchesRegularExpression('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug);
    }

    /** @param callable(string, ?int): bool $exists */
    private function generateSlug(string $title, int $maximum, callable $exists): string
    {
        $reflection = new ReflectionClass(AdminVideoController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('slugger')->setValue($controller, new AsciiSlugger());
        $method = $reflection->getMethod('uniqueSlug');
        $result = $method->invoke($controller, $title, null, $exists, $maximum);

        if (!is_string($result)) {
            throw new \UnexpectedValueException('The controller did not produce a slug.');
        }

        return $result;
    }
}
