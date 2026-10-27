<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;

/**
 * A part removal made without a record (the part page's *Remove*) can't be edited or undone in the
 * app or the API: its date, mileage, outcome and sale price stay as entered (spec.md §7.39, K67,
 * #483). The docs say so, so a route that edits or undoes one must come with a decision and new docs.
 */
final class PartRemovalRoutesTest extends AppTestCase
{
    private const array KNOWN = [
        'parts.index',
        'parts.insurer',
        'parts.insurer.declare',
        'parts.create',
        'parts.show',
        'parts.edit',
        'parts.delete',
        'parts.fit',
        'parts.remove',
        'api.parts.index',
        'api.parts.show',
        'api.parts.create',
        'api.parts.update',
        'api.parts.delete',
        'api.parts.fit',
        'api.parts.remove',
        // Files on a part, not its removal.
        'api.parts.attachments',
        'api.parts.attachments.create',
    ];

    public function testNoRouteEditsOrUndoesARemovalWithoutARecord(): void
    {
        $found = [];
        foreach ($this->createApp()->getRouteCollector()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, 'parts.') || str_starts_with($name, 'api.parts.')) {
                $found[] = $name;
            }
        }
        sort($found);
        $known = self::KNOWN;
        sort($known);

        self::assertSame($known, $found, 'a new part route: does it edit or undo a removal? Then change #483, spec §7.39 and the docs first.');
    }
}
