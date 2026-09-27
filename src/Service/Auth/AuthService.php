<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Repository\SessionRepository;
use Logbook\Repository\UserRepository;
use Logbook\Support\Security\PasswordHasher;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * First-run setup, credential checks and password changes (spec.md §7.9).
 * Session handling itself (regeneration, cookies) stays in the HTTP layer.
 */
final readonly class AuthService
{
    public function __construct(
        private UserRepository $users,
        private SessionRepository $sessions,
        private PasswordHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function setupRequired(): bool
    {
        return !$this->users->exists();
    }

    /**
     * @throws SetupAlreadyCompleted when any account exists
     */
    public function createInitialUser(SetupData $data): User
    {
        if (!$this->setupRequired()) {
            throw new SetupAlreadyCompleted('An account already exists.');
        }

        return $this->users->insert(
            Username::normalise($data->username),
            $this->hasher->hash($data->password),
            $data->displayName,
            $data->preferences,
            $this->clock->now(),
        );
    }

    /**
     * The user for these credentials, or null. Takes the same time whether
     * or not the username exists. Upgrades the hash when PHP's Argon2id
     * defaults have changed.
     */
    public function authenticate(string $username, #[SensitiveParameter] string $password): ?User
    {
        $user = $this->users->findByUsername(Username::normalise($username));
        if ($user === null) {
            $this->hasher->verifyDummy($password);

            return null;
        }

        if (!$this->hasher->verify($password, $user->passwordHash)) {
            return null;
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password), $this->clock->now());
        }

        return $user;
    }

    public function verifyPassword(User $user, #[SensitiveParameter] string $password): bool
    {
        return $this->hasher->verify($password, $user->passwordHash);
    }

    /**
     * Set a new password and end every session of this user; the caller
     * regenerates the current session so only it stays signed in.
     */
    public function changePassword(User $user, #[SensitiveParameter] string $newPassword): void
    {
        $this->users->updatePasswordHash($user->id, $this->hasher->hash($newPassword), $this->clock->now());
        $this->sessions->deleteForUser($user->id);
    }
}
