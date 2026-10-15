<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use RuntimeException;

/**
 * A fitting, removal or record save that cannot be made: it would leave a
 * part's fittings overlapping or out of order (spec.md §7.39 *Fittings*), or
 * a part is not where the change expects it. Nothing was written. The
 * message is a translation key plus parameters; dates are formatted by
 * whoever shows it.
 */
final class PartChangeRefused extends RuntimeException
{
    /**
     * @param array<string, int|string|DateTimeImmutable> $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {
        parent::__construct($key);
    }
}
