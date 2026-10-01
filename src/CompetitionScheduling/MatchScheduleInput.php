<?php

declare(strict_types=1);

namespace App\CompetitionScheduling;

final class MatchScheduleInput
{
    public function parse(string $raw, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        if ($raw === '' || strlen($raw) > 32) {
            throw new \InvalidArgumentException('Invalid match schedule.');
        }

        $utc = new \DateTimeZone('UTC');
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i\Z', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:iP', 'Y-m-d\TH:i:sP'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $raw, $utc);
            if ($date === false || $date->format($format) !== $raw) {
                continue;
            }
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) {
                continue;
            }

            $date = $date->setTimezone($utc);
            $now ??= new \DateTimeImmutable('now', $utc);
            if ($date < $now->modify('-5 minutes')) {
                throw new \InvalidArgumentException('Match schedule is in the past.');
            }

            return $date;
        }

        throw new \InvalidArgumentException('Invalid match schedule.');
    }
}
