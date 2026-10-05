<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use Logbook\Domain\User\EmailAddress;
use Logbook\Domain\User\Invitation;
use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Repository\UserRepository;
use Logbook\Service\User\AccountMail;
use Logbook\Service\User\AccountMailer;
use Logbook\Service\User\CreatedLink;
use Logbook\Service\User\OneTimeLinks;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\AfterResponse;
use Logbook\Support\Security\RateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reset links by email (spec.md §7.9 *Forgotten password*, *Admin
 * controls*). A request from the sign-in page answers the same whatever
 * was typed: who gets an email never shows, in the page or its timing
 * (the email goes after the response). Requests are limited per client
 * address and emails per account.
 */
final readonly class PasswordResets
{
    public const int PER_ADDRESS = 5;
    public const int ADDRESS_WINDOW_SECONDS = 900;
    public const int PER_ACCOUNT = 3;
    public const int ACCOUNT_WINDOW_SECONDS = 3600;

    public function __construct(
        private UserRepository $users,
        private OneTimeLinks $links,
        private AccountMailer $mailer,
        private TranslatorInterface $translator,
        private RateLimiter $limiter,
        private AfterResponse $after,
        private AppSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether *Forgotten password* is offered: email configured, password
     * sign-in on and PASSWORD_RESET_ENABLED not false.
     */
    public function isAvailable(): bool
    {
        return $this->mailer->isConfigured() && $this->settings->localLogin && $this->settings->passwordResetEnabled;
    }

    /**
     * Handle what was typed on *Forgotten password*: links are made now,
     * their emails queued until after the response.
     */
    public function request(string $typed, string $clientAddress): void
    {
        $allowed = $this->limiter->attempt('forgot-address', $clientAddress, self::PER_ADDRESS, self::ADDRESS_WINDOW_SECONDS);
        if (!$allowed) {
            $this->logger->notice('Password reset requests from {ip} are over the limit; nothing sent.', [
                'ip' => $clientAddress,
            ]);

            return;
        }

        foreach ($this->matches($typed) as $user) {
            $address = $user->email;
            if ($address === null) {
                continue;
            }
            $key = (string) $user->id;
            if (!$this->limiter->attempt('forgot-account', $key, self::PER_ACCOUNT, self::ACCOUNT_WINDOW_SECONDS)) {
                $this->logger->notice('Password reset emails for an account are over the limit; nothing sent ({ip}).', [
                    'ip' => $clientAddress,
                ]);
                continue;
            }
            $link = $this->links->reset($user, $user, sentTo: $address);
            $this->after->defer(fn (): bool => $this->mailer->send(
                $user,
                $address,
                fn (): AccountMail => $this->resetMail($user, $link, $clientAddress, $this->translator->trans(
                    'account.reset.expires_minutes',
                    ['minutes' => Invitation::SELF_RESET_VALID_MINUTES],
                )),
            ));
        }
    }

    /**
     * Where *Send reset email* (an admin's, 7 days) goes: the user's
     * confirmed address, or the pending one of an account that has no
     * password yet (*Add user*). Null when it cannot be emailed: the link
     * is shown once instead.
     */
    public function adminAddress(User $user): ?string
    {
        if (!$this->mailer->isConfigured()) {
            return null;
        }

        return $user->email ?? (!$user->hasPassword() ? $user->emailPending : null);
    }

    /**
     * Email an admin's reset or set-password link (spec.md §7.9 *Admin
     * controls*): false when it could not be sent, so the caller shows it.
     */
    public function emailAdminLink(User $admin, User $user, CreatedLink $link, string $address, string $clientAddress): bool
    {
        return $this->mailer->send(
            $user,
            $address,
            fn (): AccountMail => $user->hasPassword()
                ? $this->resetMail($user, $link, $clientAddress, $this->translator->trans('account.reset.expires_days', [
                    'days' => Invitation::VALID_DAYS,
                ]))
                : $this->welcomeMail($admin, $user, $link),
        );
    }

    /**
     * "Your Logbook password was changed", to the confirmed address.
     */
    public function notifyChanged(User $user): void
    {
        if ($user->email === null) {
            return;
        }
        $this->mailer->send($user, $user->email, fn (): AccountMail => new AccountMail(
            $this->translator->trans('account.reset.changed_subject'),
            [$this->translator->trans('account.reset.changed_body', ['username' => $user->username])],
            after: [$this->translator->trans('account.reset.changed_footer')],
        ));
    }

    /**
     * Who a typed username or address reaches: a username first; else, if
     * it looks like an address, every active user with a password and that
     * confirmed address. Only users with a password and a confirmed
     * address are returned (#160).
     *
     * @return list<User>
     */
    private function matches(string $typed): array
    {
        $username = Username::normalise($typed);
        if ($username === '' || mb_strlen($username) > EmailAddress::MAX_LENGTH) {
            return [];
        }
        $user = $this->users->findByUsername($username);
        if ($user !== null) {
            return $user->isActive() && $user->hasPassword() && $user->email !== null ? [$user] : [];
        }
        $email = EmailAddress::parse($typed);

        return $email === null ? [] : $this->users->findSignInCandidatesByEmail($email);
    }

    private function resetMail(User $user, CreatedLink $link, string $clientAddress, string $expires): AccountMail
    {
        return new AccountMail(
            $this->translator->trans('account.reset.subject'),
            [
                $this->translator->trans('account.reset.body', [
                    'username' => $user->username,
                    'ip' => $clientAddress,
                ]),
                $expires,
            ],
            $link->url,
            $this->translator->trans('account.reset.button'),
            [$this->translator->trans('account.reset.ignore')],
        );
    }

    private function welcomeMail(User $admin, User $user, CreatedLink $link): AccountMail
    {
        return new AccountMail(
            $this->translator->trans('account.added.subject', ['admin' => $admin->displayName]),
            [
                $this->translator->trans('account.added.body', [
                    'admin' => $admin->displayName,
                    'username' => $user->username,
                    'days' => Invitation::VALID_DAYS,
                ]),
            ],
            $link->url,
            $this->translator->trans('account.added.button'),
        );
    }
}
