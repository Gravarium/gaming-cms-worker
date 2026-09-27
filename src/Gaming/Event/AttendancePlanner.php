<?php

declare(strict_types=1);

namespace App\Gaming\Event;

final class AttendancePlanner
{
    /**
     * @param array<string, int> $capacities
     * @param array<string, int> $confirmedByRole
     */
    public function decide(string $role, array $capacities, array $confirmedByRole, int $waitlistLength): SignupDecision
    {
        if (!isset($capacities[$role]) || $capacities[$role] < 0) {
            throw new \InvalidArgumentException('The requested role has no valid capacity.');
        }
        if ($waitlistLength < 0) {
            throw new \InvalidArgumentException('Waitlist length cannot be negative.');
        }
        if (($confirmedByRole[$role] ?? 0) < $capacities[$role]) {
            return new SignupDecision(SignupDecision::CONFIRMED);
        }

        return new SignupDecision(SignupDecision::WAITLIST, $waitlistLength + 1);
    }

    /** @param list<array{id: int, role: string, position: int}> $waiting */
    public function nextPromotion(string $role, array $waiting): ?int
    {
        $eligible = array_values(array_filter($waiting, static fn (array $entry): bool => $entry['role'] === $role));
        usort($eligible, static fn (array $left, array $right): int => [$left['position'], $left['id']] <=> [$right['position'], $right['id']]);

        return $eligible[0]['id'] ?? null;
    }
}
