<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use RuntimeException;

/**
 * A feed that could not be read: the run fails with the reason, and the
 * current prices stay (spec.md §7.34 *Sync job*).
 */
final class FeedFailure extends RuntimeException
{
    /**
     * @param array<string, string> $parameters for the message
     */
    public function __construct(
        public readonly FeedErrorCode $error,
        public readonly array $parameters = [],
    ) {
        parent::__construct($error->value . ($parameters === [] ? '' : ' ' . json_encode($parameters)));
    }
}
