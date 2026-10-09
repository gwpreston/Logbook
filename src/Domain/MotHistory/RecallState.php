<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

/**
 * DVSA's `hasOutstandingRecall` (spec.md §7.38 *Recalls*, #325): `yes` is
 * at least one recall not yet fixed; `no`, recalls that are all fixed;
 * `unknown`, none found; `unavailable`, the recalls service failed.
 */
enum RecallState: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';
    case Unavailable = 'unavailable';

    public static function fromDvsa(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? strtolower(trim($value)) : '') ?? self::Unavailable;
    }

    public function labelKey(): string
    {
        return 'mot_history.recall.' . $this->value;
    }
}
