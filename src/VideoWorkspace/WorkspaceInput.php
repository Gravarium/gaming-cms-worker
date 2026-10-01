<?php

declare(strict_types=1);

namespace App\VideoWorkspace;

use Symfony\Component\HttpFoundation\Request;

final class WorkspaceInput
{
    public static function text(Request $request, string $key, int $max, bool $required = true): string
    {
        $value = trim($request->request->getString($key));
        if (($required && $value === '') || mb_strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Das Feld '.$key.' ist ungültig.');
        }
        return $value;
    }
    public static function integer(Request $request, string $key, int $max): int
    {
        $raw = $request->request->getString($key, '0');
        if (preg_match('/\A[0-9]{1,9}\z/', $raw) !== 1 || (int) $raw > $max) {
            throw new \InvalidArgumentException('Das Feld '.$key.' ist ungültig.');
        }
        return (int) $raw;
    }
    public static function flag(Request $request, string $key): bool
    {
        $raw = $request->request->getString($key, '0');
        if (!in_array($raw, ['0', '1'], true)) { throw new \InvalidArgumentException('Ungültige Auswahl.'); }
        return $raw === '1';
    }
    public static function visibility(Request $request, bool $allowPrivate = true): string
    {
        $raw = $request->request->getString('visibility');
        if (!in_array($raw, $allowPrivate ? ['public', 'member', 'private'] : ['public', 'member'], true)) {
            throw new \InvalidArgumentException('Ungültige Sichtbarkeit.');
        }
        return $raw;
    }
    public static function page(Request $request): int
    {
        $raw = $request->query->getString('page', '1');
        if (preg_match('/\A[1-9][0-9]{0,3}\z/', $raw) !== 1) { throw new \InvalidArgumentException('Ungültige Seite.'); }
        return (int) $raw;
    }
    public static function startsAt(Request $request): ?\DateTimeImmutable
    {
        $raw = $request->request->getString('starts_at');
        if ($raw === '') { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $raw) { throw new \InvalidArgumentException('Ungültiger Starttermin.'); }
        return $date;
    }
}
