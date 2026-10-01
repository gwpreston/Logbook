<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\External;

use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Repository\InvitationRepository;
use Logbook\Repository\UserIdentityRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Auth\Oidc\OidcOutcome;
use Logbook\Service\Auth\Oidc\OidcResult;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\UnitPreset;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Who an account vouched for from outside is in Logbook (spec.md §7.9
 * *Finding the user*, *Groups*, *Linking*), for single sign-on and header
 * sign-in alike: the linked identity, else (if the policy says) the user
 * with that username and no identity of this provider yet, else (if it
 * says) a new member, else nobody. Admin is synced from groups only when
 * the policy has admin groups. The outcomes are OIDC's, which they share.
 */
final readonly class ExternalUsers
{
    public function __construct(
        private AppSettings $settings,
        private UserRepository $users,
        private UserIdentityRepository $identities,
        private InvitationRepository $invitations,
        private ReminderSettingsStore $reminderSettings,
        private AvailableLocales $locales,
        private Transaction $transaction,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(ExternalPolicy $policy, ExternalAccount $account): OidcResult
    {
        if (!$this->inAllowedGroups($policy, $account)) {
            if ($policy->logRefusals) {
                $this->logger->notice('{label} refused "{subject}": not in {variable}.', [
                    'label' => $policy->label,
                    'subject' => $account->subject,
                    'variable' => $policy->allowedVariable,
                ]);
            }

            return new OidcResult(OidcOutcome::NotLinked);
        }
        $now = $this->clock->now();
        $outcome = OidcOutcome::SignedIn;

        $identity = $this->identities->findBySubject($policy->provider, $account->issuer, $account->subject);
        $user = $identity === null ? null : $this->users->find($identity->userId);
        if ($identity !== null && $user !== null) {
            $this->identities->touch($identity->id, $now);
        } else {
            $user = $this->linkByUsername($policy, $account);
            if ($user === null && $policy->autoCreate) {
                $user = $this->create($policy, $account);
                $outcome = $user === null ? OidcOutcome::Failed : OidcOutcome::Created;
            }
        }
        if ($user === null) {
            if ($outcome !== OidcOutcome::Failed && $policy->logRefusals) {
                $this->logger->notice('{label}: "{subject}" is not linked to any user.', [
                    'label' => $policy->label,
                    'subject' => $account->subject,
                ]);
            }

            return new OidcResult($outcome === OidcOutcome::Failed ? OidcOutcome::Failed : OidcOutcome::NotLinked);
        }
        if (!$user->isActive()) {
            $this->logger->notice('{label} refused for disabled user "{username}".', [
                'label' => $policy->label,
                'username' => $user->username,
            ]);

            return OidcResult::failed();
        }

        return new OidcResult($outcome, $this->syncAdmin($policy, $user, $account));
    }

    /**
     * Whether $userId could link this account now, without linking it:
     * the user is active, has no identity of this provider, the account is
     * nobody's and in the allowed groups.
     */
    public function canLink(ExternalPolicy $policy, int $userId, ExternalAccount $account): bool
    {
        $user = $this->users->find($userId);

        return $user !== null
            && $user->isActive()
            && $this->inAllowedGroups($policy, $account)
            && !$this->identities->hasProvider($userId, $policy->provider)
            && $this->identities->findBySubject($policy->provider, $account->issuer, $account->subject) === null;
    }

    /**
     * Link this account to a signed-in user.
     */
    public function link(ExternalPolicy $policy, int $userId, ExternalAccount $account): OidcResult
    {
        $user = $this->users->find($userId);
        if ($user === null || !$user->isActive()) {
            return OidcResult::failed();
        }
        if (!$this->inAllowedGroups($policy, $account)) {
            $this->logger->notice('Linking refused for "{username}": not in {variable}.', [
                'username' => $user->username,
                'variable' => $policy->allowedVariable,
            ]);

            return new OidcResult(OidcOutcome::NotLinked, $user);
        }
        $existing = $this->identities->findBySubject($policy->provider, $account->issuer, $account->subject);
        if ($existing !== null) {
            if ($existing->userId !== $userId) {
                $this->logger->notice('Linking refused for "{username}": that {provider} account belongs to another user.', [
                    'username' => $user->username,
                    'provider' => $policy->provider,
                ]);

                return new OidcResult(OidcOutcome::LinkTaken, $user);
            }

            return new OidcResult(OidcOutcome::Linked, $user);
        }
        if ($this->identities->hasProvider($userId, $policy->provider)) {
            return new OidcResult(OidcOutcome::AlreadyLinked, $user);
        }
        $this->identities->insert($userId, $policy->provider, $account->issuer, $account->subject, $this->clock->now());
        $this->logger->info('"{username}" linked their {provider} account.', [
            'username' => $user->username,
            'provider' => $policy->provider,
        ]);

        return new OidcResult(OidcOutcome::Linked, $user);
    }

    /**
     * Step 2: a user with the claimed username and no identity of this
     * provider yet.
     */
    private function linkByUsername(ExternalPolicy $policy, ExternalAccount $account): ?User
    {
        if (!$policy->linkByUsername || $account->username === null || trim($account->username) === '') {
            return null;
        }
        $user = $this->users->findByUsername(Username::normalise($account->username));
        if ($user === null || $this->identities->hasProvider($user->id, $policy->provider)) {
            return null;
        }
        $this->identities->insert($user->id, $policy->provider, $account->issuer, $account->subject, $this->clock->now());
        $this->logger->info('{label} linked "{subject}" to "{username}" by username.', [
            'label' => $policy->label,
            'subject' => $account->subject,
            'username' => $user->username,
        ]);

        return $user;
    }

    /**
     * Step 3: a new member without a password.
     */
    private function create(ExternalPolicy $policy, ExternalAccount $account): ?User
    {
        $base = $this->baseUsername($account);
        $name = $account->displayName;
        $displayName = $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : $base;
        $locale = str_replace('-', '_', $account->locale ?? '');
        $locale = $locale !== '' && $this->locales->supports($locale) ? $locale : $this->settings->locale;
        $email = $account->email !== null && filter_var(trim($account->email), FILTER_VALIDATE_EMAIL) !== false
            ? trim($account->email)
            : null;
        $preset = UnitPreset::Metric;
        $preferences = new DisplayPreferences(
            $locale,
            $this->settings->timezone,
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            $this->settings->currency,
            depthUnit: $preset->depth(),
        );

        return $this->transaction->run(function () use ($policy, $account, $base, $displayName, $email, $preferences): ?User {
            $now = $this->clock->now();
            $username = $this->freeUsername($base);
            if ($username === null) {
                $this->logger->warning('{label} could not find a free username for "{base}".', [
                    'label' => $policy->label,
                    'base' => $base,
                ]);

                return null;
            }
            $user = $this->users->insert($username, null, $displayName, $preferences, $now);
            $this->reminderSettings->startNewUser($user->id, $email);
            $this->identities->insert($user->id, $policy->provider, $account->issuer, $account->subject, $now);
            $this->logger->info('{label} created the user "{username}".', ['label' => $policy->label, 'username' => $username]);

            return $user;
        });
    }

    /**
     * The username claim, sanitised to the username rules; the subject's
     * start when nothing usable is left.
     */
    private function baseUsername(ExternalAccount $account): string
    {
        $clean = (string) preg_replace('/[^\p{L}\p{N}._@-]+/u', '-', Username::normalise($account->username ?? ''));
        $clean = trim($clean, '-');
        if (mb_strlen($clean) < Username::MIN_LENGTH) {
            $clean = 'user-' . substr(hash('sha256', $account->subject), 0, 8);
        }

        return mb_substr($clean, 0, Username::MAX_LENGTH - 3);
    }

    private function freeUsername(string $base): ?string
    {
        $now = $this->clock->now();
        for ($n = 1; $n <= 99; $n++) {
            $candidate = $n === 1 ? $base : $base . '-' . $n;
            if (
                Username::isValid($candidate)
                && $this->users->findByUsername($candidate) === null
                && !$this->invitations->isUsernameReserved($candidate, $now)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function inAllowedGroups(ExternalPolicy $policy, ExternalAccount $account): bool
    {
        return $policy->allowedGroups === [] || array_intersect($policy->allowedGroups, $account->groups) !== [];
    }

    /**
     * With admin groups, admin follows the groups both ways at every
     * sign-in, except that the last active admin is never demoted.
     */
    private function syncAdmin(ExternalPolicy $policy, User $user, ExternalAccount $account): User
    {
        if ($policy->adminGroups === []) {
            return $user;
        }
        $shouldBeAdmin = array_intersect($policy->adminGroups, $account->groups) !== [];
        if ($shouldBeAdmin === $user->isAdmin) {
            return $user;
        }
        if (!$shouldBeAdmin && $this->users->countActiveAdmins() <= 1) {
            $this->logger->warning(
                '{label} kept "{username}" an admin, though in none of {variable}: they are the last admin.',
                ['label' => $policy->label, 'username' => $user->username, 'variable' => $policy->adminVariable],
            );

            return $user;
        }
        $this->users->setAdmin($user->id, $shouldBeAdmin, $this->clock->now());
        $this->logger->info('{label} made "{username}" {role} ({variable}).', [
            'label' => $policy->label,
            'username' => $user->username,
            'role' => $shouldBeAdmin ? 'an admin' : 'a member',
            'variable' => $policy->adminVariable,
        ]);

        return $this->users->find($user->id) ?? $user;
    }
}
