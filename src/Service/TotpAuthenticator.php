<?php

declare(strict_types=1);

namespace App\Service;

final class TotpAuthenticator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** @param positive-int $bytes */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) { return false; }
        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -1; $offset <= 1; ++$offset) {
            if (hash_equals($this->codeForCounter($secret, $counter + $offset), $code)) { return true; }
        }
        return false;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; ++$i) {
            $raw = strtoupper(bin2hex(random_bytes(6)));
            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4).'-'.substr($raw, 8, 4);
        }
        return $codes;
    }

    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$account);
        return 'otpauth://totp/'.$label.'?secret='.rawurlencode($secret).'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    private function codeForCounter(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        $bytes = unpack('C*', $data);
        if ($bytes === false) { throw new \RuntimeException('TOTP-Daten konnten nicht verarbeitet werden.'); }
        foreach ($bytes as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer = $bits === 0 ? 0 : $buffer & ((1 << $bits) - 1);
        }
        if ($bits > 0) { $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31]; }
        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        $buffer = 0;
        $bits = 0;
        $decoded = '';
        for ($i = 0, $length = strlen($value); $i < $length; ++$i) {
            $position = strpos(self::ALPHABET, $value[$i]);
            if ($position === false) { throw new \InvalidArgumentException('Ungültiges TOTP-Geheimnis.'); }
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
