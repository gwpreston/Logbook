<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

/**
 * A defect's type, as DVSA gives it (spec.md §6 MotDefect, #333). A null
 * or unknown type is NonSpecific, so a type DVSA adds later never breaks
 * a fetch.
 */
enum MotDefectType: string
{
    case Advisory = 'advisory';
    case Minor = 'minor';
    case Major = 'major';
    case Dangerous = 'dangerous';
    case Fail = 'fail';
    case UserEntered = 'user_entered';
    case NonSpecific = 'non_specific';
    case SystemGenerated = 'system_generated';

    /**
     * DVSA's `type` (`ADVISORY`, `NON SPECIFIC`, …), case and spacing
     * forgiven.
     */
    public static function fromDvsa(mixed $type): self
    {
        if (!is_string($type)) {
            return self::NonSpecific;
        }
        $code = strtolower((string) preg_replace('/[\s_-]+/', '_', trim($type)));

        return self::tryFrom($code) ?? self::NonSpecific;
    }

    /**
     * Whether an issue made from it is open rather than watching (spec.md
     * §7.38 *Defects → issues*, #324, #328, #333).
     */
    public function opensIssue(): bool
    {
        return in_array($this, [self::Fail, self::Dangerous, self::Major], true);
    }

    public function labelKey(): string
    {
        return 'mot_history.defect.' . $this->value;
    }
}
