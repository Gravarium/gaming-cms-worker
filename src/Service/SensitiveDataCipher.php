<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SensitiveDataCipher
{
    private const MAX_PLAINTEXT_BYTES = 4096;
    private const MAX_CIPHERTEXT_LENGTH = 8192;

    private readonly string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        if ($secret === '') {
            throw new \RuntimeException('APP_SECRET darf nicht leer sein.');
        }

        $this->key = hash('sha256', $secret, true);
    }

    public function encrypt(string $plainText): string
    {
        $plainText = trim($plainText);
        if ($plainText === '') {
            throw new \InvalidArgumentException('Der geheime Wert darf nicht leer sein.');
        }
        if (strlen($plainText) > self::MAX_PLAINTEXT_BYTES) {
            throw new \InvalidArgumentException('Der geheime Wert ist zu groß.');
        }
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('Die PHP-Sodium-Erweiterung wird benötigt.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plainText, $nonce, $this->key));
    }

    public function decrypt(string $cipherText): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new \RuntimeException('Die PHP-Sodium-Erweiterung wird benötigt.');
        }
        if ($cipherText === ''
            || strlen($cipherText) > self::MAX_CIPHERTEXT_LENGTH
            || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $cipherText) !== 1
        ) {
            throw new \RuntimeException('Der verschlüsselte Wert ist beschädigt.');
        }

        $decoded = base64_decode($cipherText, true);
        $minimumLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        $maximumLength = $minimumLength + self::MAX_PLAINTEXT_BYTES;
        if ($decoded === false
            || base64_encode($decoded) !== $cipherText
            || strlen($decoded) < $minimumLength
            || strlen($decoded) > $maximumLength
        ) {
            throw new \RuntimeException('Der verschlüsselte Wert ist beschädigt.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plainText = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plainText === false) {
            throw new \RuntimeException('Der verschlüsselte Wert konnte nicht gelesen werden.');
        }

        return $plainText;
    }
}
