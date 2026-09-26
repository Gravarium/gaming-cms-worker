<?php

declare(strict_types=1);

namespace App\Service;

final class TotpAuthenticator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const MIN_SECRET_BYTES = 16;
    private const MAX_SECRET_BYTES = 64;
    private const MAX_RECOVERY_CODES = 20;

    public function generateSecret(int $bytes = 20): string
    {
        if ($bytes < self::MIN_SECRET_BYTES || $bytes > self::MAX_SECRET_BYTES) {
            throw new \InvalidArgumentException('Die TOTP-Geheimnislänge ist ungültig.');
        }

        return $this->base32Encode(random_bytes($bytes));
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return false;
        }

        $key = $this->decodeSecret($secret);
        if ($key === null) {
            return false;
        }

        $timestamp ??= time();
        if ($timestamp < 0) {
            return false;
        }

        $counter = intdiv($timestamp, 30);
        for ($offset = -1; $offset <= 1; ++$offset) {
            if (hash_equals($this->codeForCounter($key, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(int $count = 10): array
    {
        if ($count < 1 || $count > self::MAX_RECOVERY_CODES) {
            throw new \InvalidArgumentException('Die Anzahl der Wiederherstellungscodes ist ungültig.');
        }

        $codes = [];
        for ($i = 0; $i < $count; ++$i) {
            $raw = strtoupper(bin2hex(random_bytes(6)));
            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4).'-'.substr($raw, 8, 4);
        }

        return $codes;
    }

    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $normalizedSecret = $this->decodeSecret($secret);
        if ($normalizedSecret === null) {
            throw new \InvalidArgumentException('Ungültiges TOTP-Geheimnis.');
        }

        $account = trim($account);
        $issuer = trim($issuer);
        if ($account === '' || $issuer === '' || mb_strlen($account) > 200 || mb_strlen($issuer) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $account) === 1
            || preg_match('/[\x00-\x1F\x7F]/u', $issuer) === 1
        ) {
            throw new \InvalidArgumentException('Die TOTP-Profildaten sind ungültig.');
        }

        $canonicalSecret = $this->base32Encode($normalizedSecret);
        $label = rawurlencode($issuer.':'.$account);

        return 'otpauth://totp/'.$label.'?secret='.rawurlencode($canonicalSecret).'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    private function codeForCounter(string $key, int $counter): string
    {
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decodeSecret(string $secret): ?string
    {
        $normalized = strtoupper($secret);
        if ($normalized === '' || preg_match('/\A[A-Z2-7]+\z/', $normalized) !== 1) {
            return null;
        }

        try {
            $decoded = $this->base32Decode($normalized);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (strlen($decoded) < self::MIN_SECRET_BYTES || strlen($decoded) > self::MAX_SECRET_BYTES) {
            return null;
        }

        return $this->base32Encode($decoded) === $normalized ? $decoded : null;
    }

    private function base32Encode(string $data): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        $bytes = unpack('C*', $data);
        if ($bytes === false) {
            throw new \RuntimeException('TOTP-Daten konnten nicht verarbeitet werden.');
        }

        foreach ($bytes as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer = $bits === 0 ? 0 : $buffer & ((1 << $bits) - 1);
        }
        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Z2-7]+\z/', $value) !== 1) {
            throw new \InvalidArgumentException('Ungültiges TOTP-Geheimnis.');
        }

        $buffer = 0;
        $bits = 0;
        $decoded = '';
        for ($i = 0, $length = strlen($value); $i < $length; ++$i) {
            $position = strpos(self::ALPHABET, $value[$i]);
            if ($position === false) {
                throw new \InvalidArgumentException('Ungültiges TOTP-Geheimnis.');
            }
            $buffer = ($buffer << 5) | $position;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
                $buffer = $bits === 0 ? 0 : $buffer & ((1 << $bits) - 1);
            }
        }

        return $decoded;
    }
}
