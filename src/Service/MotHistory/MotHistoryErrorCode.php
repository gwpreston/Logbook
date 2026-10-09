<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

/**
 * Why DVSA couldn't be asked or answered (spec.md §7.38 *Requests*): each
 * has a message (`mot_history.error.*`).
 */
enum MotHistoryErrorCode: string
{
    case Credentials = 'credentials';
    case TokenUrl = 'token_url';
    case Unauthorised = 'unauthorised';
    case RateLimited = 'rate_limited';
    case Timeout = 'timeout';
    case Network = 'network';
    case BadRequest = 'bad_request';
    case HttpStatus = 'http_status';
    case TooLarge = 'too_large';
    case InvalidResponse = 'invalid_response';
    case InvalidIdentifier = 'invalid_identifier';
    /** Logbook's own per-person limit (MotHistoryLimit), before anything is sent. */
    case TooMany = 'too_many';

    public function messageKey(): string
    {
        return 'mot_history.error.' . $this->value;
    }

    /**
     * What an owner or someone adding a vehicle is told (spec.md §7.38
     * *Requests*): a problem with Logbook's credentials is the admin's to
     * fix, on Settings, so they get "MOT history isn't available right now".
     */
    public function userMessageKey(): string
    {
        return $this->isCredentials() ? 'mot_history.error.not_available' : $this->messageKey();
    }

    public function isCredentials(): bool
    {
        return in_array($this, [self::Credentials, self::TokenUrl, self::Unauthorised], true);
    }
}
