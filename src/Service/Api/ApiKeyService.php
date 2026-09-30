<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Api\ApiKey;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\User;
use Logbook\Repository\ApiKeyRepository;
use Logbook\Support\Config\AppSettings;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * API keys (spec.md §7.20). A token is `lbk_` + 32 random bytes in
 * base64url; only its HMAC-SHA256 keyed with SESSION_SECRET is stored, like
 * session ids and calendar tokens, so the token is shown once and changing
 * the secret disables every key.
 */
final readonly class ApiKeyService
{
    public const string PREFIX = 'lbk_';
    public const int NAME_MAX_LENGTH = 100;
    /** last_used_at is written at most this often. */
    public const int TOUCH_SECONDS = 60;
    private const string TOKEN_PATTERN = '/^lbk_[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private ApiKeyRepository $keys,
        private AppSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    public function create(User $user, string $name, ApiScope $scope): CreatedApiKey
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new LogicException('An API key needs a name of 1 to ' . self::NAME_MAX_LENGTH . ' characters.');
        }

        $token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = $this->keys->insert($user->id, $name, $scope, $this->hash($token), $this->clock->now());
        $key = $this->keys->find($id) ?? throw new LogicException('The API key just created was not found.');

        return new CreatedApiKey($key, $token);
    }

    /**
     * Whether a bearer value looks like one of our tokens at all.
     */
    public static function isWellFormed(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /**
     * The live key a token belongs to, or null for a malformed, unknown or
     * revoked one.
     */
    public function verify(#[\SensitiveParameter] string $token): ?ApiKey
    {
        if (!self::isWellFormed($token)) {
            return null;
        }

        $hash = $this->hash($token);
        $found = $this->keys->findByHash($hash);
        if ($found === null || !hash_equals($found['hash'], $hash) || $found['key']->isRevoked()) {
            return null;
        }

        return $found['key'];
    }

    /**
     * Record that a key was used, at most once a minute.
     */
    public function touch(ApiKey $key): void
    {
        $now = $this->clock->now();
        $last = $key->lastUsedAt;
        if ($last === null || $now->getTimestamp() - $last->getTimestamp() >= self::TOUCH_SECONDS) {
            $this->keys->touch($key->id, $now);
        }
    }

    /**
     * @return list<ApiKey> newest first, revoked ones too
     */
    public function keysOf(User $user): array
    {
        return $this->keys->listForUser($user->id);
    }

    /**
     * The user's own key with this id, or null.
     */
    public function keyOf(User $user, int $id): ?ApiKey
    {
        $key = $this->keys->find($id);

        return $key !== null && $key->userId === $user->id ? $key : null;
    }

    /**
     * Revoke one of the user's keys. Immediate and final; false when it
     * is not theirs or already revoked.
     */
    public function revoke(User $user, int $id): bool
    {
        return $this->keyOf($user, $id) !== null && $this->keys->revoke($id, $this->clock->now());
    }

    private function hash(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, $this->settings->sessionSecret);
    }
}
