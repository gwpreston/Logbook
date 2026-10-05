<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use Logbook\Domain\User\EmailAddress;
use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Repository\SessionRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Database\Transaction;
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
        private ReminderSettingsStore $settings,
        private Transaction $transaction,
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

        $hash = $this->hasher->hash($data->password);

        return $this->transaction->run(function () use ($data, $hash): User {
            $user = $this->users->insert(
                Username::normalise($data->username),
                $hash,
                $data->displayName,
                $data->preferences,
                $this->clock->now(),
                isAdmin: true,
            );
            $this->settings->startNewUser($user->id);

            return $user;
        });
    }

    /**
     * The user for these credentials, or null. Takes the same time whether
     * or not the username exists. Upgrades the hash when PHP's Argon2id
     * defaults have changed.
     *
     * $login is a username or, when no user has it as one and it has an
     * `@`, a confirmed email address held by exactly one active user with
     * a password (spec.md §7.9 *Sign-in by username or email*, #162).
     */
    public function authenticate(string $login, #[SensitiveParameter] string $password): ?User
    {
        $user = $this->findForSignIn($login);
        if ($user === null || $user->passwordHash === null) {
            // No such user, or one with single sign-on only (Phase 23.1).
            $this->hasher->verifyDummy($password);

            return null;
        }

        if (!$this->hasher->verify($password, $user->passwordHash)) {
            return null;
        }
        if (!$user->isActive()) {
            // Refused like a wrong password (spec.md §7.9), after the same work.
            return null;
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password), $this->clock->now());
        }

        return $user;
    }

    private function findForSignIn(string $login): ?User
    {
        $normalised = Username::normalise($login);
        $user = $this->users->findByUsername($normalised);
        if ($user !== null || !str_contains($normalised, '@')) {
            return $user;
        }
        $email = EmailAddress::parse($normalised);
        $candidates = $email === null ? [] : $this->users->findSignInCandidatesByEmail($email);

        // A shared address is ambiguous: those users sign in by username.
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    public function verifyPassword(User $user, #[SensitiveParameter] string $password): bool
    {
        return $user->passwordHash !== null && $this->hasher->verify($password, $user->passwordHash);
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
