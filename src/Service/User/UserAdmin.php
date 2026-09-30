<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use DateInterval;
use Logbook\Domain\User\Invitation;
use Logbook\Domain\User\InvitationKind;
use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\InvitationRepository;
use Logbook\Repository\SessionRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Http\AbsoluteUrl;
use Psr\Clock\ClockInterface;

/**
 * Settings → Users (spec.md §7.9): invitations and reset links, admins,
 * disabling and deleting. The rules live here: there is always an active
 * admin, nobody disables or deletes themselves, and a user who owns
 * vehicles is not deleted.
 */
final readonly class UserAdmin
{
    public function __construct(
        private UserRepository $users,
        private InvitationRepository $invitations,
        private SessionRepository $sessions,
        private SettingRepository $settings,
        private VehicleRepository $vehicles,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AppSettings $app,
        private AbsoluteUrl $urls,
    ) {
    }

    /**
     * @return list<UserRow> oldest account first
     */
    public function users(): array
    {
        $seen = $this->sessions->lastActivityByUser();

        return array_map(
            static fn (User $user): UserRow => new UserRow($user, $seen[$user->id] ?? null),
            $this->users->listAll(),
        );
    }

    public function find(int $id): ?User
    {
        return $this->users->find($id);
    }

    /**
     * @return list<Invitation> newest first
     */
    public function openLinks(): array
    {
        return $this->invitations->listOpen($this->clock->now());
    }

    /**
     * Whether a new account may take this (normalised) username: no user
     * has it and no open invite holds it.
     */
    public function isUsernameFree(string $username): bool
    {
        return $this->users->findByUsername($username) === null
            && !$this->invitations->isUsernameReserved($username, $this->clock->now());
    }

    public function invite(User $admin, string $username, string $displayName, bool $isAdmin): CreatedLink
    {
        return $this->link($admin, InvitationKind::Invite, null, Username::normalise($username), $displayName, $isAdmin);
    }

    /**
     * A one-time link to set a new password; the user's sessions end now and
     * any earlier reset link stops working.
     */
    public function resetLink(User $admin, User $user): CreatedLink
    {
        return $this->transaction->run(function () use ($admin, $user): CreatedLink {
            $this->invitations->revokeResetsFor($user->id, $this->clock->now());
            $this->sessions->deleteForUser($user->id);

            return $this->link($admin, InvitationKind::Reset, $user->id, $user->username, $user->displayName, $user->isAdmin);
        });
    }

    public function revoke(int $invitationId): bool
    {
        $invitation = $this->invitations->find($invitationId);
        if ($invitation === null || !$invitation->isOpen($this->clock->now())) {
            return false;
        }
        $this->invitations->revoke($invitationId, $this->clock->now());

        return true;
    }

    public function setAdmin(User $user, bool $isAdmin): ?UserRefusal
    {
        if (!$isAdmin && $this->isLastActiveAdmin($user)) {
            return UserRefusal::LastAdmin;
        }
        $this->users->setAdmin($user->id, $isAdmin, $this->clock->now());

        return null;
    }

    /**
     * Block sign-in and end every session at once; API keys and the calendar
     * feed stop working because they check the user.
     */
    public function disable(User $actor, User $user): ?UserRefusal
    {
        if ($actor->id === $user->id) {
            return UserRefusal::Yourself;
        }
        if ($this->isLastActiveAdmin($user)) {
            return UserRefusal::LastAdmin;
        }
        $this->transaction->run(function () use ($user): void {
            $now = $this->clock->now();
            $this->users->setDisabledAt($user->id, $now, $now);
            $this->sessions->deleteForUser($user->id);
        });

        return null;
    }

    public function enable(User $user): void
    {
        $this->users->setDisabledAt($user->id, null, $this->clock->now());
    }

    /**
     * @return list<Vehicle> the vehicles they own, archived ones too
     */
    public function ownedVehicles(User $user): array
    {
        return $this->vehicles->listByIds($this->vehicles->idsOwnedBy($user->id, null));
    }

    /**
     * Delete the account: their shares, keys, links, sessions, settings and
     * deliveries go with it (foreign keys); entries they added to other
     * people's vehicles stay, with no author ("a former user").
     */
    public function delete(User $actor, User $user): ?UserRefusal
    {
        if ($actor->id === $user->id) {
            return UserRefusal::Yourself;
        }
        if ($this->ownedVehicles($user) !== []) {
            return UserRefusal::OwnsVehicles;
        }
        if ($this->isLastActiveAdmin($user)) {
            return UserRefusal::LastAdmin;
        }
        $this->transaction->run(function () use ($user): void {
            $this->settings->deleteAllOf($user->id);
            $this->users->delete($user->id);
        });

        return null;
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return $user->isAdmin && $user->isActive() && $this->users->countActiveAdmins() <= 1;
    }

    private function link(
        User $admin,
        InvitationKind $kind,
        ?int $userId,
        string $username,
        string $displayName,
        bool $isAdmin,
    ): CreatedLink {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $id = $this->invitations->insert(
            InvitationTokens::hash($token, $this->app->sessionSecret),
            $kind,
            $admin->id,
            $userId,
            $username,
            $displayName,
            $isAdmin,
            $now->add(new DateInterval('P' . Invitation::VALID_DAYS . 'D')),
            $now,
        );
        $invitation = $this->invitations->find($id);
        assert($invitation instanceof Invitation);

        return new CreatedLink($invitation, $token, $this->urls->route('invite.accept', ['token' => $token]));
    }
}
