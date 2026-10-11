<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use DateTimeZone;
use Logbook\Tests\Support\Golden\Rng;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Undo restores (Phase 45.1 property): deleting an entry and entering it
 * again gives exactly the figures there were before, counts included,
 * whichever kind of entry it is.
 */
#[Group('property')]
final class UndoRestoresPropertyTest extends PropertyTestCase
{
    #[DataProvider('seeds')]
    public function testDeletingAnEntryAndAddingItBackChangesNoFigure(int $seed): void
    {
        [$app, $plan, $writer] = $this->household($seed);
        $figures = $this->figures($app);
        $before = $figures->snapshot($writer->owner, $writer->fleet());
        $zone = new DateTimeZone($plan->ownerZone);

        $rng = new Rng($seed + 3);
        $undone = [];
        foreach (array_keys($writer->stored) as $key) {
            // Two entries a vehicle, of whatever kinds the seed picks.
            for ($i = 0; $i < 2 && $writer->stored[$key] !== []; $i++) {
                $entry = $writer->remove($key, $rng->between(0, count($writer->stored[$key]) - 1), $zone);
                $writer->add($key, $entry, $zone);
                $undone[] = "{$key} {$entry->kind}";
            }
        }

        self::assertSame(
            $before,
            $figures->snapshot($writer->owner, $writer->fleet()),
            'undoing ' . implode(', ', $undone) . ': ' . $plan->describe(),
        );
    }
}
