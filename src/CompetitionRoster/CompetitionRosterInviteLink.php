<?php

declare(strict_types=1);

namespace App\CompetitionRoster;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CompetitionRosterInviteLink
{
    public const TTL_SECONDS = 3600;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function issue(
        Competition $competition,
        CompetitionParticipant $participant,
        User $captain,
        string $email,
    ): string {
        $competitionId = $competition->getId();
        $participantId = $participant->getId();
        $captainId = $captain->getId();
        $email = mb_strtolower(trim($email));

        if (
            $competitionId === null
            || $participantId === null
            || $captainId === null
            || $participant->getCompetition()?->getId() !== $competitionId
            || $participant->getCaptain()?->getId() !== $captainId
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($email) > 180
        ) {
            throw new \InvalidArgumentException('A verified roster invitation needs valid persisted identities and an email address.');
        }

        $issuedAt = time();
        $nonce = bin2hex(random_bytes(24));
        $payload = [
            'v' => 1,
            'competition' => $competitionId,
            'participant' => $participantId,
            'captain' => $captainId,
            'email' => $this->emailFingerprint($email),
            'roster' => $this->rosterFingerprint($competition, $participant),
            'issuedAt' => $issuedAt,
            'expiresAt' => $issuedAt + self::TTL_SECONDS,
            'nonce' => $nonce,
        ];
        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, $this->secret);
        $token = $encodedPayload.'.'.$signature;

        $cacheKey = $this->cacheKey($nonce);
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            throw new \RuntimeException('A unique invitation nonce could not be allocated.');
        }
        $item->set(hash('sha256', $token));
        $item->expiresAfter(self::TTL_SECONDS);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('The roster invitation could not be stored.');
        }

        return $token;
    }

    public function isValid(
        string $token,
        Competition $competition,
        CompetitionParticipant $participant,
        User $user,
    ): bool {
        return $this->validate($token, $competition, $participant, $user, false);
    }

    public function consume(
        string $token,
        Competition $competition,
        CompetitionParticipant $participant,
        User $user,
    ): bool {
        return $this->validate($token, $competition, $participant, $user, true);
    }

    private function validate(
        string $token,
        Competition $competition,
        CompetitionParticipant $participant,
        User $user,
        bool $consume,
    ): bool {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || preg_match('/^[a-f0-9]{64}$/D', $parts[1]) !== 1) {
            return false;
        }
        [$encodedPayload, $signature] = $parts;
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $encodedPayload) !== 1) {
            return false;
        }
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        $decoded = self::base64UrlDecode($encodedPayload);
        if ($decoded === null) {
            return false;
        }
        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($payload)) {
            return false;
        }

        $expectedKeys = ['v', 'competition', 'participant', 'captain', 'email', 'roster', 'issuedAt', 'expiresAt', 'nonce'];
        if (array_diff(array_keys($payload), $expectedKeys) !== [] || array_diff($expectedKeys, array_keys($payload)) !== []) {
            return false;
        }

        $competitionId = $competition->getId();
        $participantId = $participant->getId();
        $captainId = $participant->getCaptain()?->getId();
        $userId = $user->getId();
        if (
            !is_int($payload['v']) || $payload['v'] !== 1
            || !is_int($payload['competition']) || $competitionId === null || $payload['competition'] !== $competitionId
            || !is_int($payload['participant']) || $participantId === null || $payload['participant'] !== $participantId
            || !is_int($payload['captain']) || $captainId === null || $payload['captain'] !== $captainId
            || !is_string($payload['email'])
            || !is_string($payload['roster'])
            || !is_int($payload['issuedAt'])
            || !is_int($payload['expiresAt'])
            || !is_string($payload['nonce'])
            || preg_match('/^[a-f0-9]{48}$/D', $payload['nonce']) !== 1
            || $payload['issuedAt'] > time() + 60
            || $payload['expiresAt'] <= time()
            || $payload['expiresAt'] > $payload['issuedAt'] + self::TTL_SECONDS
            || $userId === null
            || !$user->isActive()
            || !$user->isEmailVerified()
            || $participant->containsUser($user)
            || $participant->getCompetition()?->getId() !== $competitionId
            || !hash_equals($this->emailFingerprint($user->getEmail()), $payload['email'])
            || !hash_equals($this->rosterFingerprint($competition, $participant), $payload['roster'])
        ) {
            return false;
        }

        $cacheKey = $this->cacheKey($payload['nonce']);
        $item = $this->cache->getItem($cacheKey);
        $expectedTokenHash = hash('sha256', $token);
        $cachedHash = $item->get();
        if (!$item->isHit() || !is_string($cachedHash) || !hash_equals($expectedTokenHash, $cachedHash)) {
            return false;
        }

        return !$consume || $this->cache->deleteItem($cacheKey);
    }

    private function emailFingerprint(string $email): string
    {
        return hash_hmac('sha256', "competition-roster-email\0".mb_strtolower(trim($email)), $this->secret);
    }

    private function rosterFingerprint(Competition $competition, CompetitionParticipant $participant): string
    {
        $memberIds = $participant->getRosterUserIds();
        $captainId = $participant->getCaptain()?->getId();
        if ($captainId !== null) {
            $memberIds[] = $captainId;
        }
        $memberIds = array_values(array_unique(array_filter(
            $memberIds,
            static fn (mixed $id): bool => self::isPositiveUserId($id),
        )));
        sort($memberIds, SORT_NUMERIC);

        return hash('sha256', json_encode([
            'competition' => $competition->getId(),
            'participant' => $participant->getId(),
            'captain' => $captainId,
            'members' => $memberIds,
            'status' => $participant->getStatus(),
            'teamSize' => $competition->getTeamSize(),
        ], JSON_THROW_ON_ERROR));
    }

    private function cacheKey(string $nonce): string
    {
        return 'competition_roster_invite_'.hash('sha256', $nonce);
    }

    private static function isPositiveUserId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return is_string($decoded) ? $decoded : null;
    }
}
