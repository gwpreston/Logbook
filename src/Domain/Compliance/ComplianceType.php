<?php

declare(strict_types=1);

namespace Logbook\Domain\Compliance;

/**
 * Kinds of compliance document. Stored as the code; `other` covers anything
 * else (a toll tag, a parking permit), named by the document's title.
 */
enum ComplianceType: string
{
    case Insurance = 'insurance';
    /** Pollution under control certificate (PUC/PUCC) or emissions test. */
    case Pollution = 'pollution';
    case Registration = 'registration';
    /** Roadworthiness inspection (MOT, TÜV, WOF, …). */
    case Inspection = 'inspection';
    case Other = 'other';

    /**
     * Icon name in the vendored sprite (assets/vendor/icons.svg).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Insurance => 'verified_user',
            self::Pollution => 'eco',
            self::Registration => 'badge',
            self::Inspection => 'fact_check',
            self::Other => 'description',
        };
    }

    /**
     * Whether a newer document of this type supersedes older ones. "Other"
     * documents are unrelated to each other, so each stands alone.
     */
    public function isSuperseded(): bool
    {
        return $this !== self::Other;
    }
}
