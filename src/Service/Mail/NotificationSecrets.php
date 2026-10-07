<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

use InvalidArgumentException;
use Logbook\Repository\NotificationSecretRepository;
use Logbook\Service\Ai\SecretBox;
use Logbook\Service\Ai\SecretUnreadable;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Throwable;

/**
 * Notification secrets (spec.md §6 NotificationSecret, §7.11): sealed by
 * the AI secret box under their own key (`logbook-notify`), or an `env:NAME`
 * reference for the installation's. Opened only to send; never shown back.
 * A null owner is the installation; any other is a user's channel secret,
 * which is **never** an `env:` reference (Phase 36.2): one saved by a
 * member would let them have the server send a variable's value to an
 * address they chose. Such a value is refused when stored and never read.
 */
final readonly class NotificationSecrets
{
    public const string SMTP_PASSWORD = 'smtp_password';

    private SecretBox $box;

    public function __construct(
        private NotificationSecretRepository $repository,
        SecretBox $box,
        private ClockInterface $clock,
    ) {
        $this->box = $box->withInfo(SecretBox::NOTIFY);
    }

    /**
     * Whether a typed value can be stored: a reference always, a value only
     * with a `SESSION_SECRET`.
     */
    public function canStore(string $value): bool
    {
        return $this->box->canStore($value);
    }

    public function canSeal(): bool
    {
        return $this->box->canStore('x');
    }

    public function store(?int $ownerId, string $name, #[SensitiveParameter] string $value): void
    {
        if ($ownerId !== null && SecretBox::isReference($value)) {
            throw new InvalidArgumentException('A user\'s notification secret cannot be an env: reference.');
        }
        $this->repository->put($ownerId, $name, $this->box->store($value), $this->clock->now());
    }

    public function remove(?int $ownerId, string $name): void
    {
        $this->repository->remove($ownerId, $name);
    }

    /**
     * What a typed value means at send time: an `env:NAME` reference reads
     * the variable now; anything else is the value itself.
     *
     * @throws SecretUnreadable an `env:` variable that is not set
     */
    public function resolve(string $name, #[SensitiveParameter] string $typed): string
    {
        return SecretBox::isReference($typed) ? $this->box->open($name, trim($typed)) : $typed;
    }

    /**
     * The value, or null when none is stored.
     *
     * @throws SecretUnreadable sealed with another key, or an unset `env:` variable
     */
    public function open(?int $ownerId, string $name): ?string
    {
        $stored = $this->repository->find($ownerId, $name);
        if ($stored !== null && $ownerId !== null && SecretBox::variable($stored) !== null) {
            throw new SecretUnreadable($name);
        }

        return $stored === null ? null : $this->box->open($name, $stored);
    }

    /**
     * What is stored, never the value: null (nothing), `saved`, `env`
     * (with the variable), `unset` (an `env:` variable that is not set) or
     * `unreadable` (sealed with another `SESSION_SECRET`).
     *
     * @return array{state: string, variable: ?string}|null
     */
    public function state(?int $ownerId, string $name): ?array
    {
        $stored = $this->repository->find($ownerId, $name);
        if ($stored === null) {
            return null;
        }
        $variable = SecretBox::variable($stored);
        if ($ownerId !== null && $variable !== null) {
            return ['state' => 'unreadable', 'variable' => null];
        }
        try {
            $this->box->open($name, $stored);
            $state = $variable === null ? 'saved' : 'env';
        } catch (SecretUnreadable) {
            $state = $variable === null ? 'unreadable' : 'unset';
        }

        return ['state' => $state, 'variable' => $variable];
    }

    /**
     * Every value that can be opened, for redaction (spec.md §7.30).
     *
     * @return list<string>
     */
    public function openAll(): array
    {
        $values = [];
        foreach ($this->repository->all() as $stored) {
            try {
                $values[] = $this->box->open('notification', $stored);
            } catch (Throwable) {
                // Unreadable here, so it cannot be printed either.
            }
        }

        return $values;
    }
}
