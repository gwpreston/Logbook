<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use RuntimeException;

/**
 * DVSA couldn't be asked or answered: nothing is stored, and the reason is
 * shown redacted (spec.md §7.38 *Requests*).
 */
final class MotHistoryFailure extends RuntimeException
{
    /**
     * @param array<string, string> $parameters for the message
     */
    public function __construct(
        public readonly MotHistoryErrorCode $error,
        public readonly array $parameters = [],
    ) {
        parent::__construct($error->value . ($parameters === [] ? '' : ' ' . json_encode($parameters)));
    }
}
