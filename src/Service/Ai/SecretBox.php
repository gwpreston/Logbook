<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Support\Config\AppSettings;
use SodiumException;

/**
 * Seals and opens AI secrets (spec.md §7.25 *Secrets*): libsodium
 * `secretbox` with a key derived from `SESSION_SECRET` (HKDF-SHA256, info
 * `logbook-ai`), stored as `v1:` + base64(nonce ‖ ciphertext); or an
 * `env:NAME` reference, read at call time. Without a `SESSION_SECRET`
 * there is no key, so only references can be stored.
 *
 * Notification secrets (Phase 36.1, spec.md §6 NotificationSecret) use the
 * same box under their own key (`withInfo('logbook-notify')`), so one can
 * never be opened as the other.
 */
final readonly class SecretBox
{
    public const string AI = 'logbook-ai';
    public const string NOTIFY = 'logbook-notify';
    /** Entry webhooks' signing secrets (Phase 39.3, spec.md §6 Webhook). */
    public const string WEBHOOK = 'logbook-webhook';

    private const string PREFIX = 'v1:';
    private const string REFERENCE = '/^env:([A-Za-z_][A-Za-z0-9_]*)$/';

    public function __construct(
        private AppSettings $settings,
        /** The HKDF info string: which key this box seals with. */
        private string $info = self::AI,
    ) {
    }

    /**
     * The same box, sealing with the key for another use.
     */
    public function withInfo(string $info): self
    {
        return new self($this->settings, $info);
    }

    public static function isReference(string $value): bool
    {
        return preg_match(self::REFERENCE, trim($value)) === 1;
    }

    /**
     * Whether a typed value can be stored: a reference always, a key only
     * with a `SESSION_SECRET`.
     */
    public function canStore(string $value): bool
    {
        return self::isReference($value) || $this->settings->sessionSecret !== '';
    }

    /**
     * What to store for a typed value: the reference as given, or the
     * value sealed.
     */
    public function store(string $value): string
    {
        $value = trim($value);
        if (self::isReference($value)) {
            return $value;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key();
        $sealed = sodium_crypto_secretbox($value, $nonce, $key);
        sodium_memzero($key);

        return self::PREFIX . base64_encode($nonce . $sealed);
    }

    /**
     * The secret's value at call time.
     *
     * @throws SecretUnreadable when it was sealed with another key or the variable is unset
     */
    public function open(string $slot, string $stored): string
    {
        if (preg_match(self::REFERENCE, $stored, $m) === 1) {
            $value = $this->settings->env->string($m[1]);
            if ($value === '') {
                throw new SecretUnreadable($slot, $m[1]);
            }

            return $value;
        }

        if ($this->settings->sessionSecret === '' || !str_starts_with($stored, self::PREFIX)) {
            throw new SecretUnreadable($slot);
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new SecretUnreadable($slot);
        }

        $key = $this->key();
        try {
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key,
            );
        } catch (SodiumException) {
            $plain = false;
        } finally {
            sodium_memzero($key);
        }
        if ($plain === false) {
            throw new SecretUnreadable($slot);
        }

        return $plain;
    }

    /**
     * The `env:` variable a stored value reads, or null for a sealed one.
     */
    public static function variable(string $stored): ?string
    {
        return preg_match(self::REFERENCE, $stored, $m) === 1 ? $m[1] : null;
    }

    private function key(): string
    {
        return hash_hkdf('sha256', $this->settings->sessionSecret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $this->info);
    }
}
