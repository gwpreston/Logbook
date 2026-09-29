<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A tyre change (or an edit that moves one) that cannot be saved: the replay
 * failed or the form's choices do not fit the tyres as they are (spec.md
 * §7.17). Nothing was written. The message is a translation key plus
 * parameters; dates are formatted by whoever shows it.
 */
final class TyreChangeRefused extends RuntimeException
{
    /**
     * @param array<string, int|string|TranslatableInterface|DateTimeImmutable> $params
     * @param string $field the form field the message belongs to ('form' for the whole form)
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        public readonly string $field = 'form',
    ) {
        parent::__construct($key);
    }
}
