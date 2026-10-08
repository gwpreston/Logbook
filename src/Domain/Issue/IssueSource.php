<?php

declare(strict_types=1);

namespace Logbook\Domain\Issue;

/**
 * Where an issue came from (spec.md §6 Issue): typed by the owner, a
 * scanned invoice's recommended work (Phase 40.2), or an MOT advisory
 * (Phase 41).
 */
enum IssueSource: string
{
    case Manual = 'manual';
    case RecommendedWork = 'recommended_work';
    case MotAdvisory = 'mot_advisory';
}
