<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Domain\User\EmailAddress;
use Logbook\Domain\User\Invitation;
use Logbook\Domain\User\InvitationKind;
use Logbook\Domain\User\User;
use Logbook\Repository\InvitationRepository;
use Logbook\Repository\UserRepository;
use Logbook\Support\Database\Transaction;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A user's email address (spec.md §7.9 *Email addresses*, #157): a new one
 * waits as pending until its 24-hour link is used, and is used for nothing
 * until then (#164). The old confirmed address hears about a change.
 */
final readonly class EmailAddresses
{
    public function __construct(
        private UserRepository $users,
        private InvitationRepository $invitations,
        private OneTimeLinks $links,
        private AccountMailer $mailer,
        private TranslatorInterface $translator,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    public function canConfirm(): bool
    {
        return $this->mailer->isConfigured();
    }

    /**
     * Change the user's address (already checked by the caller: their
     * current password, a valid address). Null or empty removes it.
     */
    public function change(User $user, ?string $address): EmailChange
    {
        $address = $address === null || trim($address) === '' ? null : EmailAddress::normalise($address);
        $now = $this->clock->now();

        if ($address === null) {
            if ($user->email === null && $user->emailPending === null) {
                return EmailChange::Unchanged;
            }
            $this->transaction->run(function () use ($user, $now): void {
                $this->users->setEmails($user->id, null, null, $now);
                $this->invitations->revokeOpenFor($user->id, InvitationKind::Email, $now);
            });
            if ($user->email !== null) {
                $this->notice($user, $user->email, 'account.email.removed_notice');
            }

            return EmailChange::Removed;
        }

        if ($address === $user->email) {
            if ($user->emailPending === null) {
                return EmailChange::Unchanged;
            }
            $this->cancel($user);

            return EmailChange::Unchanged;
        }
        if ($address === $user->emailPending) {
            return EmailChange::Unchanged;
        }

        $this->users->setEmails($user->id, $user->email, $address, $now);
        $updated = $this->users->find($user->id);
        assert($updated instanceof User);
        $sent = $this->sendConfirmation($updated, $updated);
        if ($user->email !== null) {
            $this->notice($user, $user->email, 'account.email.change_notice', ['address' => $address]);
        }

        return $sent ? EmailChange::Pending : EmailChange::PendingNotSent;
    }

    /**
     * A new link for the pending address (*Send the link again*, or an OIDC
     * address without `email_verified`). False when there is none to send,
     * or it could not be sent.
     */
    public function sendConfirmation(User $by, User $user): bool
    {
        if ($user->emailPending === null || !$this->mailer->isConfigured()) {
            return false;
        }
        $link = $this->links->emailConfirmation($by, $user, $user->emailPending);

        return $this->mailer->send($user, $user->emailPending, fn (): AccountMail => new AccountMail(
            $this->translator->trans('account.email.confirm_subject'),
            [
                $this->translator->trans('account.email.confirm_body', [
                    'username' => $user->username,
                    'address' => $user->emailPending,
                    'hours' => Invitation::EMAIL_VALID_HOURS,
                ]),
            ],
            $link->url,
            $this->translator->trans('account.email.confirm_button'),
            [$this->translator->trans('account.email.confirm_ignore')],
        ));
    }

    /**
     * Drop the pending address; its link stops working.
     */
    public function cancel(User $user): void
    {
        $this->transaction->run(function () use ($user): void {
            $now = $this->clock->now();
            $this->users->setEmails($user->id, $user->email, null, $now);
            $this->invitations->revokeOpenFor($user->id, InvitationKind::Email, $now);
        });
    }

    /**
     * Use a confirmation link: the pending address becomes the user's. Null
     * when the link was used meanwhile or the pending address changed.
     */
    public function confirm(Invitation $invitation): ?User
    {
        if ($invitation->kind !== InvitationKind::Email || $invitation->userId === null || $invitation->email === null) {
            return null;
        }
        $userId = $invitation->userId;
        $address = $invitation->email;

        return $this->transaction->run(function () use ($invitation, $userId, $address): ?User {
            $now = $this->clock->now();
            if (
                !$this->invitations->markUsed($invitation->id, $now)
                || !$this->users->confirmPendingEmail($userId, $address, $now)
            ) {
                return null;
            }

            return $this->users->find($userId);
        });
    }

    /**
     * Make a pending address confirmed without a link (*Add user*'s
     * set-password link was used: it proved the mailbox, #165).
     */
    public function confirmPending(User $user): void
    {
        if ($user->emailPending !== null) {
            $this->users->confirmPendingEmail($user->id, $user->emailPending, $this->clock->now());
            $this->invitations->revokeOpenFor($user->id, InvitationKind::Email, $this->clock->now());
        }
    }

    /**
     * @param array<string, string|int> $params
     */
    private function notice(User $user, string $address, string $key, array $params = []): void
    {
        $this->mailer->send($user, $address, fn (): AccountMail => new AccountMail(
            $this->translator->trans('account.email.notice_subject'),
            [$this->translator->trans($key, ['username' => $user->username] + $params)],
            after: [$this->translator->trans('account.email.notice_footer')],
        ));
    }
}
