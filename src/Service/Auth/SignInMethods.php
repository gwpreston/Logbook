<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use Logbook\Domain\User\User;
use Logbook\Domain\User\UserIdentity;
use Logbook\Repository\UserIdentityRepository;
use Logbook\Support\Config\AppSettings;
use Psr\Log\LoggerInterface;

/**
 * How each user can sign in (spec.md §7.9 *Linking*, *Admin view*): a
 * password (when local sign-in is on) and linked provider accounts.
 * Removing an identity is refused while it is the user's only way in.
 */
final readonly class SignInMethods
{
    public function __construct(
        private UserIdentityRepository $identities,
        private AppSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<UserIdentity>
     */
    public function identities(User $user): array
    {
        return $this->identities->forUser($user->id);
    }

    /**
     * @return array<int, list<UserIdentity>> by user id
     */
    public function identitiesByUser(): array
    {
        return $this->identities->byUser();
    }

    /**
     * How messages name an identity's provider: OIDC_PROVIDER_NAME, or
     * "Proxy" (the word in every catalogue so far; templates use
     * `users.method.proxy`).
     */
    public function providerName(UserIdentity $identity): string
    {
        return $identity->provider === UserIdentity::PROXY ? 'Proxy' : $this->settings->oidc->providerName;
    }

    public function find(int $identityId): ?UserIdentity
    {
        return $this->identities->find($identityId);
    }

    public function canUsePassword(User $user): bool
    {
        return $this->settings->localLogin && $user->hasPassword();
    }

    /**
     * Whether this identity may go: the user keeps a password they can
     * use, or another identity.
     */
    public function canRemove(User $user, UserIdentity $identity): bool
    {
        if ($identity->userId !== $user->id) {
            return false;
        }
        foreach ($this->identities->forUser($user->id) as $other) {
            if ($other->id !== $identity->id) {
                return true;
            }
        }

        return $this->canUsePassword($user);
    }

    /**
     * Remove one of the user's identities; false when it is not theirs or
     * it is their only way in.
     */
    public function remove(User $user, int $identityId, ?User $by = null): bool
    {
        $identity = $this->identities->find($identityId);
        if ($identity === null || !$this->canRemove($user, $identity)) {
            return false;
        }
        $this->identities->delete($identity->id);
        $this->logger->info('The single sign-on account of "{username}" was unlinked by "{by}".', [
            'username' => $user->username,
            'by' => ($by ?? $user)->username,
        ]);

        return true;
    }
}
