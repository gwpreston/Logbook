<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * Why a feed couldn't be read: each has a message (`fuel_prices.error.*`).
 */
enum FeedErrorCode: string
{
    case Credentials = 'credentials';
    case Unauthorised = 'unauthorised';
    case RateLimited = 'rate_limited';
    case Timeout = 'timeout';
    case Network = 'network';
    case HttpStatus = 'http_status';
    case TooLarge = 'too_large';
    case InvalidResponse = 'invalid_response';
    case TooManyPages = 'too_many_pages';
    case Cancelled = 'cancelled';

    public function messageKey(): string
    {
        return 'fuel_prices.error.' . $this->value;
    }
}
