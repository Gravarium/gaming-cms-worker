<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Repository\AccountTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AccountTokenManager
{
    public function __construct(private readonly AccountTokenRepository $tokens, private readonly EntityManagerInterface $entityManager) {}

    /** @return array{AccountToken, string} */
    public function issue(User $user, string $purpose, \DateInterval $lifetime): array
    {
        $this->tokens->revokeActive($user, $purpose);
        $plainToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = (new AccountToken())
            ->setUser($user)
            ->setPurpose($purpose)
            ->setTokenHash(hash('sha256', $plainToken))
            ->setExpiresAt((new \DateTimeImmutable())->add($lifetime));
        $this->entityManager->persist($token);
        return [$token, $plainToken];
    }

    public function resolve(string $plainToken, string $purpose): ?AccountToken
    {
        if ($plainToken === '' || strlen($plainToken) > 200) { return null; }
        return $this->tokens->usable(hash('sha256', $plainToken), $purpose);
    }

    public function consume(string $plainToken, string $purpose): ?AccountToken
    {
        if ($plainToken === '' || strlen($plainToken) > 200) { return null; }
        return $this->tokens->consumeUsable(hash('sha256', $plainToken), $purpose);
    }

    public function revoke(User $user, string $purpose): void
    {
        $this->tokens->revokeActive($user, $purpose);
    }
}
