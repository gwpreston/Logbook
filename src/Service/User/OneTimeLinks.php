<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use DateInterval;
use Logbook\Domain\User\Invitation;
use Logbook\Domain\User\InvitationKind;
use Logbook\Domain\User\User;
use Logbook\Repository\InvitationRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Http\AbsoluteUrl;
use Psr\Clock\ClockInterface;

/**
 * One-time links for an existing user (spec.md §6 Invitation): a reset, an
 * email confirmation. A new one revokes the user's other open links of the
 * same kind. Only the token's keyed hash is stored.
 */
final readonly class OneTimeLinks
{
    public function __construct(
        private InvitationRepository $invitations,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AppSettings $app,
        private AbsoluteUrl $urls,
    ) {
    }

    /**
     * A reset link: an admin's (7 days) or, when $by is the user, one they
     * asked for themselves (60 minutes, #159). $sentTo is the address it is
     * emailed to: using the link confirms that address if it is still the
     * user's pending one (*Add user*, #165).
     */
    public function reset(User $by, User $user, ?string $sentTo = null): CreatedLink
    {
        $lifetime = $by->id === $user->id
            ? new DateInterval('PT' . Invitation::SELF_RESET_VALID_MINUTES . 'M')
            : new DateInterval('P' . Invitation::VALID_DAYS . 'D');

        return $this->create(InvitationKind::Reset, $by, $user, $lifetime, $sentTo, 'invite.accept');
    }

    /**
     * The link that confirms $address for $user (24 hours).
     */
    public function emailConfirmation(User $by, User $user, string $address): CreatedLink
    {
        $lifetime = new DateInterval('PT' . Invitation::EMAIL_VALID_HOURS . 'H');

        return $this->create(InvitationKind::Email, $by, $user, $lifetime, $address, 'email.confirm');
    }

    private function create(
        InvitationKind $kind,
        User $by,
        User $user,
        DateInterval $lifetime,
        ?string $email,
        string $route,
    ): CreatedLink {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $invitation = $this->transaction->run(function () use ($kind, $by, $user, $lifetime, $email, $token): Invitation {
            $now = $this->clock->now();
            $this->invitations->revokeOpenFor($user->id, $kind, $now);
            $id = $this->invitations->insert(
                InvitationTokens::hash($token, $this->app->sessionSecret),
                $kind,
                $by->id,
                $user->id,
                $user->username,
                $user->displayName,
                $user->isAdmin,
                $now->add($lifetime),
                $now,
                $email,
            );
            $invitation = $this->invitations->find($id);
            assert($invitation instanceof Invitation);

            return $invitation;
        });

        return new CreatedLink($invitation, $token, $this->urls->route($route, ['token' => $token]));
    }
}
