<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Domain\User\Invitation;
use Logbook\Domain\User\InvitationKind;
use Logbook\Domain\User\User;
use Logbook\Repository\InvitationRepository;
use Logbook\Repository\SessionRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Auth\SetupData;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Security\PasswordHasher;
use Psr\Clock\ClockInterface;

/**
 * Opening a one-time link (spec.md §7.9 `/invite/{token}`): an invite
 * creates the account, a reset sets a new password. A used, expired,
 * revoked or unknown link, or a reset for a disabled user, is no link.
 */
final readonly class InvitationService
{
    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private SessionRepository $sessions,
        private PasswordHasher $hasher,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AppSettings $app,
        private ReminderSettingsStore $settings,
    ) {
    }

    public function open(#[\SensitiveParameter] string $token): ?Invitation
    {
        if (preg_match(InvitationTokens::PATTERN, $token) !== 1) {
            return null;
        }
        $hash = InvitationTokens::hash($token, $this->app->sessionSecret);
        $found = $this->invitations->findByHash($hash);
        if ($found === null || !hash_equals($found['hash'], $hash)) {
            return null;
        }
        $invitation = $found['invitation'];
        if (!$invitation->isOpen($this->clock->now())) {
            return null;
        }
        if ($invitation->kind !== InvitationKind::Invite) {
            $user = $invitation->userId === null ? null : $this->users->find($invitation->userId);
            if ($user === null || !$user->isActive()) {
                return null;
            }
        }

        return $invitation;
    }

    /**
     * Create the invited account (username and admin flag from the invite)
     * and use the link up, in one transaction. Null when the link was used
     * meanwhile or the username was taken after all.
     */
    public function acceptInvite(Invitation $invitation, SetupData $data): ?User
    {
        return $this->transaction->run(function () use ($invitation, $data): ?User {
            $now = $this->clock->now();
            $taken = $this->users->findByUsername($invitation->username) !== null;
            if ($taken || !$this->invitations->markUsed($invitation->id, $now)) {
                return null;
            }

            $user = $this->users->insert(
                $invitation->username,
                $this->hasher->hash($data->password),
                $data->displayName,
                $data->preferences,
                $now,
                $invitation->isAdmin,
            );
            $this->settings->startNewUser($user->id);

            return $user;
        });
    }

    /**
     * Use up a break-glass sign-in link (spec.md §7.9): the user to sign
     * in, or null when it was used meanwhile.
     */
    public function acceptLogin(Invitation $invitation): ?User
    {
        $user = $invitation->userId === null ? null : $this->users->find($invitation->userId);
        if ($user === null || !$user->isActive() || !$this->invitations->markUsed($invitation->id, $this->clock->now())) {
            return null;
        }

        return $user;
    }

    /**
     * Set the new password of a reset link and end the user's sessions.
     */
    public function acceptReset(Invitation $invitation, #[\SensitiveParameter] string $password): ?User
    {
        $user = $invitation->userId === null ? null : $this->users->find($invitation->userId);
        if ($user === null) {
            return null;
        }

        return $this->transaction->run(function () use ($invitation, $user, $password): ?User {
            $now = $this->clock->now();
            if (!$this->invitations->markUsed($invitation->id, $now)) {
                return null;
            }
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password), $now);
            $this->sessions->deleteForUser($user->id);

            return $this->users->find($user->id);
        });
    }
}
