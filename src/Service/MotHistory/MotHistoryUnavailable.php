<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use RuntimeException;

/**
 * A fetch that can't start (spec.md §7.38): the provider is off
 * (`off`), the owner hasn't confirmed for this vehicle (`unconfirmed`),
 * or it has no registration or VIN (`no_identifier`). Nothing is sent.
 */
final class MotHistoryUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function messageKey(): string
    {
        return 'mot_history.unavailable.' . $this->reason;
    }
}
