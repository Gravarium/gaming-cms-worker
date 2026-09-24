<?php

declare(strict_types=1);

namespace App\Editorial;

final readonly class EditorialScore
{
    /**
     * @param list<string> $pros
     * @param list<string> $cons
     */
    public function __construct(
        public int $score,
        public string $methodology,
        public string $platform,
        public string $reviewedVersion,
        public array $pros,
        public array $cons,
        public string $disclosure,
    ) {
        if ($score < 0 || $score > 100) {
            throw new \InvalidArgumentException('Editorial score must be between 0 and 100.');
        }
        foreach ([$methodology, $platform, $reviewedVersion, $disclosure] as $required) {
            if (trim($required) === '' || mb_strlen($required) > 1000) {
                throw new \InvalidArgumentException('Editorial context is required and bounded.');
            }
        }
        if (count($pros) > 20 || count($cons) > 20) {
            throw new \InvalidArgumentException('Pro and contra lists are bounded.');
        }
    }
}
