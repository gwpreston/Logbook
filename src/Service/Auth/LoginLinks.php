<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use DateInterval;
use Logbook\Domain\User\Invitation;
use Logbook\Domain\User\InvitationKind;
use Logbook\Domain\User\User;
use Logbook\Repository\InvitationRepository;
use Logbook\Service\User\CreatedLink;
use Logbook\Service\User\InvitationTokens;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Http\AbsoluteUrl;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Break-glass sign-in links (spec.md §7.9): `php bin/auth.php login-link`
 * makes a one-time link that signs the user in within ten minutes, even
 * with password sign-in off and the identity provider down. Stored as a
 * keyed hash, like invitations; a new link replaces the user's earlier one.
 */
final readonly class LoginLinks
{
    public function __construct(
        private InvitationRepository $invitations,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AppSettings $app,
        private AbsoluteUrl $urls,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return CreatedLink|null null for a disabled user
     */
    public function create(User $user): ?CreatedLink
    {
        if (!$user->isActive()) {
            return null;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $invitation = $this->transaction->run(function () use ($user, $token): Invitation {
            $now = $this->clock->now();
            $this->invitations->revokeOpenFor($user->id, InvitationKind::Login, $now);
            $id = $this->invitations->insert(
                InvitationTokens::hash($token, $this->app->sessionSecret),
                InvitationKind::Login,
                $user->id,
                $user->id,
                $user->username,
                $user->displayName,
                $user->isAdmin,
                $now->add(new DateInterval('PT' . Invitation::LOGIN_VALID_MINUTES . 'M')),
                $now,
            );
            $invitation = $this->invitations->find($id);
            assert($invitation instanceof Invitation);

            return $invitation;
        });
        $this->logger->notice('A break-glass sign-in link was made on the command line for "{username}".', [
            'username' => $user->username,
        ]);

        return new CreatedLink($invitation, $token, $this->urls->route('login.link', ['token' => $token]));
    }
}
