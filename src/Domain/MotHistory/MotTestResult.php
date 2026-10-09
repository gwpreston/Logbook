<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

/**
 * A test's result (spec.md §6 MotTest): DVSA lists passes and fails only.
 */
enum MotTestResult: string
{
    case Passed = 'passed';
    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'mot_history.result.' . $this->value;
    }
}
