<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * Why an AI request did not answer (spec.md §7.25 *Errors*). Users see a
 * translated message by code; admins also see the provider's text, redacted.
 */
enum ErrorCode: string
{
    case Timeout = 'timeout';
    case Unreachable = 'unreachable';
    case Auth = 'auth';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case TooLarge = 'too_large';
    case CapReached = 'cap_reached';
    case Busy = 'busy';
    case NotAcknowledged = 'not_acknowledged';
    case SecretUnreadable = 'secret_unreadable';
    case BadResponse = 'bad_response';
    case Provider = 'provider';
    /** AI is off for this user or install, or the connection is disabled. */
    case Disabled = 'disabled';
    /** The task has no model, or the model lacks what the task needs. */
    case Unassigned = 'unassigned';

    public function messageKey(): string
    {
        return 'ai.error.' . $this->value;
    }

    /**
     * How the usage log records it: stopped before sending, timed out, or
     * failed on the way.
     */
    public function outcome(): Outcome
    {
        return match ($this) {
            self::Timeout => Outcome::Timeout,
            self::TooLarge, self::CapReached, self::Busy, self::NotAcknowledged,
            self::SecretUnreadable, self::Disabled, self::Unassigned => Outcome::Refused,
            default => Outcome::Error,
        };
    }
}
