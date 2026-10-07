<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use Logbook\Repository\UserRepository;

/**
 * The message telling a user that channels switched themselves off after
 * failing 5 times in a row (spec.md §7.11 *Switched off after failures*),
 * in their language.
 */
final readonly class SwitchOffNotice
{
    public function __construct(
        private UserRepository $users,
        private NotificationComposer $composer,
    ) {
    }

    /**
     * @param non-empty-list<string> $labels
     */
    public function compose(Recipient $recipient, array $labels): ?Notification
    {
        $user = $this->users->find($recipient->userId);

        return $user === null ? null : $this->composer->channelOff($user, $labels);
    }
}
