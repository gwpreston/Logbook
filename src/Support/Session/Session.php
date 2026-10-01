<?php

declare(strict_types=1);

namespace Logbook\Support\Session;

use DateTimeImmutable;

/**
 * The current visitor's session data, for one request.
 *
 * Loaded and saved by SessionMiddleware; nothing is written (and no cookie is
 * sent) until something is stored. Values must be JSON-serialisable.
 */
final class Session
{
    private const string USER_ID = '_user_id';
    private const string FLASH = '_flash';
    private const string CSRF = '_csrf';
    private const string SSO = '_sso';
    private const string WELCOME = '_welcome';

    private bool $dirty = false;
    private bool $regenerated = false;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly ?string $token,
        private array $data,
        private readonly ?DateTimeImmutable $lastActivity,
    ) {
    }

    public static function start(): self
    {
        return new self(null, [], null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function resume(string $token, array $data, DateTimeImmutable $lastActivity): self
    {
        return new self($token, $data, $lastActivity);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] !== $value) {
            $this->data[$key] = $value;
            $this->dirty = true;
        }
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    public function userId(): ?int
    {
        $id = $this->data[self::USER_ID] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * Start an authenticated session under a new id (fixation protection).
     * CSRF tokens issued before sign-in are discarded, and so is what a
     * previous sign-in left (its single sign-on ID token, a pending welcome).
     */
    public function signIn(int $userId): void
    {
        $this->regenerate();
        $this->remove(self::SSO);
        $this->remove(self::WELCOME);
        $this->set(self::USER_ID, $userId);
    }

    /**
     * Remember that this session came from single sign-on (spec.md §7.9),
     * with the ID token only when the provider's sign-out needs it.
     */
    public function markSingleSignOn(?string $idToken): void
    {
        $this->set(self::SSO, $idToken === null ? ['id_token' => null] : ['id_token' => $idToken]);
    }

    public function cameFromSingleSignOn(): bool
    {
        return is_array($this->data[self::SSO] ?? null);
    }

    public function singleSignOnIdToken(): ?string
    {
        $sso = $this->data[self::SSO] ?? null;

        return is_array($sso) && is_string($sso['id_token'] ?? null) ? $sso['id_token'] : null;
    }

    /**
     * A user created on first single sign-on is offered the welcome form
     * once, then sent on to $next.
     */
    public function startWelcome(?string $next): void
    {
        $this->set(self::WELCOME, ['next' => $next]);
    }

    public function welcomePending(): bool
    {
        return is_array($this->data[self::WELCOME] ?? null);
    }

    /**
     * End the welcome; where to go next.
     */
    public function finishWelcome(): ?string
    {
        $welcome = $this->data[self::WELCOME] ?? null;
        $this->remove(self::WELCOME);

        return is_array($welcome) && is_string($welcome['next'] ?? null) ? $welcome['next'] : null;
    }

    /**
     * Forget everything and continue under a new id (sign-out). Flash
     * messages added afterwards survive into the next request.
     */
    public function destroy(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    /**
     * Keep the data but move it to a new id, invalidating the old one.
     */
    public function regenerate(): void
    {
        unset($this->data[self::CSRF]);
        $this->regenerated = true;
        $this->dirty = true;
    }

    /**
     * Queue a one-off message for the next page shown.
     *
     * @param 'success'|'error'|'warning'|'info' $type
     * @param array<string, int|string> $params ICU parameters for $messageKey
     */
    public function flash(string $type, string $messageKey, array $params = []): void
    {
        $flashes = $this->flashes();
        $flashes[] = ['type' => $type, 'key' => $messageKey, 'params' => $params];
        $this->set(self::FLASH, $flashes);
    }

    /**
     * Return and clear queued flash messages.
     *
     * @return list<array{type: string, key: string, params: array<string, int|string>}>
     */
    public function takeFlashes(): array
    {
        $flashes = $this->flashes();
        if ($flashes !== []) {
            $this->remove(self::FLASH);
        }

        return $flashes;
    }

    /**
     * @return array<string, string> CSRF token name → value (slim/csrf storage)
     */
    public function csrfTokens(): array
    {
        $tokens = $this->data[self::CSRF] ?? [];
        if (!is_array($tokens)) {
            return [];
        }

        $valid = [];
        foreach ($tokens as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $valid[$name] = $value;
            }
        }

        return $valid;
    }

    /**
     * @param array<string, string> $tokens
     */
    public function setCsrfTokens(array $tokens): void
    {
        $tokens === [] ? $this->remove(self::CSRF) : $this->set(self::CSRF, $tokens);
    }

    // --- State for SessionMiddleware ------------------------------------------

    public function token(): ?string
    {
        return $this->token;
    }

    public function isPersisted(): bool
    {
        return $this->token !== null;
    }

    public function lastActivity(): ?DateTimeImmutable
    {
        return $this->lastActivity;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function wasRegenerated(): bool
    {
        return $this->regenerated;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @return list<array{type: string, key: string, params: array<string, int|string>}>
     */
    private function flashes(): array
    {
        $stored = $this->data[self::FLASH] ?? [];
        if (!is_array($stored)) {
            return [];
        }

        $flashes = [];
        foreach ($stored as $flash) {
            if (is_array($flash) && is_string($flash['type'] ?? null) && is_string($flash['key'] ?? null)) {
                $params = [];
                foreach (is_array($flash['params'] ?? null) ? $flash['params'] : [] as $name => $value) {
                    if (is_string($name) && (is_int($value) || is_string($value))) {
                        $params[$name] = $value;
                    }
                }
                $flashes[] = ['type' => $flash['type'], 'key' => $flash['key'], 'params' => $params];
            }
        }

        return $flashes;
    }
}
