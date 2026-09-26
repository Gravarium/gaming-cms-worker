<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Repository\AccountTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AccountTokenManager
{
    private const MAX_TOKEN_LIFETIME_DAYS = 7;
    private const TOKEN_LENGTH = 43;

    public function __construct(
        private readonly AccountTokenRepository $tokens,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array{AccountToken, string} */
    public function issue(User $user, string $purpose, \DateInterval $lifetime): array
    {
        $this->assertSupportedPurpose($purpose);

        $now = new \DateTimeImmutable();
        $expiresAt = $now->add($lifetime);
        $latestAllowedExpiry = $now->add(new \DateInterval('P'.self::MAX_TOKEN_LIFETIME_DAYS.'D'));
        if ($expiresAt <= $now || $expiresAt > $latestAllowedExpiry) {
            throw new \InvalidArgumentException('Die Token-Lebensdauer ist ungültig oder zu lang.');
        }

        $this->tokens->revokeActive($user, $purpose);
        $plainToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = (new AccountToken())
            ->setUser($user)
            ->setPurpose($purpose)
            ->setTokenHash(hash('sha256', $plainToken))
            ->setExpiresAt($expiresAt);
        $this->entityManager->persist($token);

        return [$token, $plainToken];
    }

    public function resolve(string $plainToken, string $purpose): ?AccountToken
    {
        if (!$this->isUsableInput($plainToken, $purpose)) {
            return null;
        }

        return $this->tokens->usable(hash('sha256', $plainToken), $purpose);
    }

    public function consume(string $plainToken, string $purpose): ?AccountToken
    {
        if (!$this->isUsableInput($plainToken, $purpose)) {
            return null;
        }

        return $this->tokens->consumeUsable(hash('sha256', $plainToken), $purpose);
    }

    public function revoke(User $user, string $purpose): void
    {
        $this->assertSupportedPurpose($purpose);
        $this->tokens->revokeActive($user, $purpose);
    }

    private function isUsableInput(string $plainToken, string $purpose): bool
    {
        return $this->isValidPlainToken($plainToken) && $this->isSupportedPurpose($purpose);
    }

    private function isValidPlainToken(string $plainToken): bool
    {
        return strlen($plainToken) === self::TOKEN_LENGTH
            && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $plainToken) === 1;
    }

    private function isSupportedPurpose(string $purpose): bool
    {
        return in_array($purpose, [
            AccountToken::PURPOSE_EMAIL_VERIFICATION,
            AccountToken::PURPOSE_PASSWORD_RESET,
        ], true);
    }

    private function assertSupportedPurpose(string $purpose): void
    {
        if (!$this->isSupportedPurpose($purpose)) {
            throw new \InvalidArgumentException('Der Token-Zweck ist nicht freigegeben.');
        }
    }
}
