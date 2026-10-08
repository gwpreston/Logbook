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

    public function messageKey(): string
    {
        return 'mot_history.error.' . $this->value;
    }
}
