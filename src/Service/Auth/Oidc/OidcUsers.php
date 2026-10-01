<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use Logbook\Domain\Auth\OidcLinkMode;
use Logbook\Domain\User\User;
use Logbook\Domain\User\UserIdentity;
use Logbook\Domain\User\Username;
use Logbook\Repository\InvitationRepository;
use Logbook\Repository\UserIdentityRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\UnitPreset;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Who a validated provider account is in Logbook (spec.md §7.9 *Finding
 * the user*, *Groups*, *Linking*): the linked identity, else (if chosen)
 * the user with that username and no identity yet, else (if chosen) a new
 * member, else nobody. Admin is synced from groups only when asked.
 */
final readonly class OidcUsers
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

    /**
     * @param array<string, mixed> $claims
     */
    public function resolve(string $issuer, string $subject, array $claims): OidcResult
    {
        if (!$this->inAllowedGroups($claims)) {
            $this->logger->notice('Single sign-on refused "{subject}": not in OIDC_ALLOWED_GROUPS.', ['subject' => $subject]);

            return new OidcResult(OidcOutcome::NotLinked);
        }
        $now = $this->clock->now();
        $outcome = OidcOutcome::SignedIn;

        $identity = $this->identities->findBySubject(UserIdentity::OIDC, $issuer, $subject);
        $user = $identity === null ? null : $this->users->find($identity->userId);
        if ($identity !== null && $user !== null) {
            $this->identities->touch($identity->id, $now);
        } else {
            $user = $this->linkByUsername($issuer, $subject, $claims);
            if ($user === null && $this->settings->oidc->autoCreate) {
                $user = $this->create($issuer, $subject, $claims);
                $outcome = $user === null ? OidcOutcome::Failed : OidcOutcome::Created;
            }
        }
        if ($user === null) {
            if ($outcome !== OidcOutcome::Failed) {
                $this->logger->notice('Single sign-on: "{subject}" is not linked to any user.', ['subject' => $subject]);
            }

            return new OidcResult($outcome === OidcOutcome::Failed ? OidcOutcome::Failed : OidcOutcome::NotLinked);
        }
        if (!$user->isActive()) {
            $this->logger->notice('Single sign-on refused for disabled user "{username}".', ['username' => $user->username]);

            return OidcResult::failed();
        }

        return new OidcResult($outcome, $this->syncAdmin($user, $claims));
    }

    /**
     * Link this provider account to a signed-in user (Settings → Account).
     *
     * @param array<string, mixed> $claims
     */
    public function link(int $userId, string $issuer, string $subject, array $claims): OidcResult
    {
        $user = $this->users->find($userId);
        if ($user === null || !$user->isActive()) {
            return OidcResult::failed();
        }
        if (!$this->inAllowedGroups($claims)) {
            $this->logger->notice('Linking refused for "{username}": not in OIDC_ALLOWED_GROUPS.', [
                'username' => $user->username,
            ]);

            return new OidcResult(OidcOutcome::NotLinked, $user);
        }
        $existing = $this->identities->findBySubject(UserIdentity::OIDC, $issuer, $subject);
        if ($existing !== null) {
            if ($existing->userId !== $userId) {
                $this->logger->notice('Linking refused for "{username}": that provider account belongs to another user.', [
                    'username' => $user->username,
                ]);

                return new OidcResult(OidcOutcome::LinkTaken, $user);
            }

            return new OidcResult(OidcOutcome::Linked, $user);
        }
        if ($this->identities->hasProvider($userId, UserIdentity::OIDC)) {
            return new OidcResult(OidcOutcome::AlreadyLinked, $user);
        }
        $this->identities->insert($userId, UserIdentity::OIDC, $issuer, $subject, $this->clock->now());
        $this->logger->info('"{username}" linked their single sign-on account.', ['username' => $user->username]);

        return new OidcResult(OidcOutcome::Linked, $user);
    }

    /**
     * Step 2 (OIDC_LINK=username): a user with the claimed username and no
     * provider account yet.
     *
     * @param array<string, mixed> $claims
     */
    private function linkByUsername(string $issuer, string $subject, array $claims): ?User
    {
        if ($this->settings->oidc->link !== OidcLinkMode::Username) {
            return null;
        }
        $claimed = $claims[$this->settings->oidc->usernameClaim] ?? null;
        if (!is_string($claimed) || trim($claimed) === '') {
            return null;
        }
        $user = $this->users->findByUsername(Username::normalise($claimed));
        if ($user === null || $this->identities->hasProvider($user->id, UserIdentity::OIDC)) {
            return null;
        }
        $this->identities->insert($user->id, UserIdentity::OIDC, $issuer, $subject, $this->clock->now());
        $this->logger->info('Single sign-on linked "{subject}" to "{username}" by username.', [
            'subject' => $subject,
            'username' => $user->username,
        ]);

        return $user;
    }

    /**
     * Step 3 (OIDC_AUTO_CREATE): a new member without a password.
     *
     * @param array<string, mixed> $claims
     */
    private function create(string $issuer, string $subject, array $claims): ?User
    {
        $base = $this->baseUsername($claims, $subject);
        $name = $claims['name'] ?? null;
        $displayName = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : $base;
        $locale = $claims['locale'] ?? null;
        $locale = is_string($locale) ? str_replace('-', '_', $locale) : '';
        $locale = $locale !== '' && $this->locales->supports($locale) ? $locale : $this->settings->locale;
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

        return $this->transaction->run(function () use ($issuer, $subject, $base, $displayName, $preferences): ?User {
            $now = $this->clock->now();
            $username = $this->freeUsername($base);
            if ($username === null) {
                $this->logger->warning('Single sign-on could not find a free username for "{base}".', ['base' => $base]);

                return null;
            }
            $user = $this->users->insert($username, null, $displayName, $preferences, $now);
            $this->reminderSettings->startNewUser($user->id);
            $this->identities->insert($user->id, UserIdentity::OIDC, $issuer, $subject, $now);
            $this->logger->info('Single sign-on created the user "{username}".', ['username' => $username]);

            return $user;
        });
    }

    /**
     * The username claim, sanitised to the username rules; the subject's
     * start when nothing usable is left.
     *
     * @param array<string, mixed> $claims
     */
    private function baseUsername(array $claims, string $subject): string
    {
        $claimed = $claims[$this->settings->oidc->usernameClaim] ?? null;
        $clean = (string) preg_replace('/[^\p{L}\p{N}._@-]+/u', '-', Username::normalise(is_string($claimed) ? $claimed : ''));
        $clean = trim($clean, '-');
        if (mb_strlen($clean) < Username::MIN_LENGTH) {
            $clean = 'user-' . substr(hash('sha256', $subject), 0, 8);
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

    /**
     * @param array<string, mixed> $claims
     */
    private function inAllowedGroups(array $claims): bool
    {
        $allowed = $this->settings->oidc->allowedGroups;

        return $allowed === [] || array_intersect($allowed, $this->groups($claims)) !== [];
    }

    /**
     * With OIDC_ADMIN_GROUPS, admin follows the groups both ways at every
     * sign-in, except that the last active admin is never demoted.
     *
     * @param array<string, mixed> $claims
     */
    private function syncAdmin(User $user, array $claims): User
    {
        $adminGroups = $this->settings->oidc->adminGroups;
        if ($adminGroups === []) {
            return $user;
        }
        $shouldBeAdmin = array_intersect($adminGroups, $this->groups($claims)) !== [];
        if ($shouldBeAdmin === $user->isAdmin) {
            return $user;
        }
        if (!$shouldBeAdmin && $this->users->countActiveAdmins() <= 1) {
            $this->logger->warning(
                'Single sign-on kept "{username}" an admin, though in none of OIDC_ADMIN_GROUPS: they are the last admin.',
                ['username' => $user->username],
            );

            return $user;
        }
        $this->users->setAdmin($user->id, $shouldBeAdmin, $this->clock->now());
        $this->logger->info('Single sign-on made "{username}" {role} (OIDC_ADMIN_GROUPS).', [
            'username' => $user->username,
            'role' => $shouldBeAdmin ? 'an admin' : 'a member',
        ]);

        return $this->users->find($user->id) ?? $user;
    }

    /**
     * The groups claim as a list of names (a list, or one string).
     *
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private function groups(array $claims): array
    {
        $value = $claims[$this->settings->oidc->groupsClaim] ?? [];
        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
