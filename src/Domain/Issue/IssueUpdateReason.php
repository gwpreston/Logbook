<?php

declare(strict_types=1);

namespace Logbook\Domain\Issue;

/**
 * Why an automatic timeline line was written (spec.md §6 IssueUpdate), so
 * it is shown in the reader's language rather than stored as text.
 */
enum IssueUpdateReason: string
{
    /** The status was changed on the edit form or with *Add update*. */
    case Edited = 'edited';
    case Watch = 'watch';
    case StopWatching = 'stop_watching';
    /** A service record was linked as the fix. */
    case Fixed = 'fixed';
    case FixedWithoutRecord = 'fixed_without_record';
    /** The last fixing record was unticked or unlinked. */
    case RecordUnlinked = 'record_unlinked';
    case RecordDeleted = 'record_deleted';
    /** *It's back*: a fixed issue reopened. */
    case Back = 'back';
    /** *Reopen* from *Look again*. */
    case Reopened = 'reopened';
    /** *Done* on the look-again reminder: the point is cleared. */
    case LookedAt = 'looked_at';

    public function labelKey(): string
    {
        return 'issue.update.reason.' . $this->value;
    }
}
