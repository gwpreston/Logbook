<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;

/**
 * `draft_modification` (Phase 50.2, spec.md §7.26, §7.39, #441): a change the user has made to a car, as a
 * modification card for their Add. The title and notes are the user's words; the date is resolved by
 * Logbook and never in the future. No parts: the owner adds those in the form the card's *Edit* opens, or on
 * the part pages. The cost is only drafted for a user who may see costs on that vehicle.
 */
final class DraftModification extends DraftTool
{
    public function kind(): DraftKind
    {
        return DraftKind::Modification;
    }

    protected function description(): string
    {
        return 'Draft a modification for the user to add: something they changed on the car (an exhaust, '
            . 'coilovers, an ECU remap, wheels, a stereo), in their own words. Not servicing or repairs, which have '
            . 'their own tool. Parts are added by the user afterwards.';
    }

    protected function properties(): array
    {
        return [
            'date' => self::dateProperty('When it was done'),
            'title' => ['type' => 'string', 'description' => 'What was done, in the user\'s words, up to 120 characters.'],
            'category' => ['type' => 'string', 'description' => 'What kind of modification, only if the user named it '
                . '("exhaust", "remap", "wheels", "suspension").'],
            'odometer' => self::numberProperty('The odometer reading then'),
            'distance_unit' => self::distanceUnitProperty(),
            'cost' => self::numberProperty('What the work cost (0 is fine); left out if the user did not say'),
            'fitted_by' => ['type' => 'string', 'description' => 'Who fitted it: a garage\'s name, or "Self".'],
            'notes' => ['type' => 'string', 'description' => 'More of the user\'s own words, if they gave any.'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $date = $this->date($user, $arguments, 'date') ?? $this->resolver->today($user);
        $word = $arguments->string('category');

        return self::given([
            'title' => $arguments->string('title'),
            'done_on' => $date->format('Y-m-d'),
            // A category is optional here: a word that matches none is left out, so it is Other.
            'category' => $word === null ? null : $this->resolver->category($user, DraftKind::Modification, $word),
            'odometer' => $this->number($user, $arguments, 'odometer'),
            'distance_unit' => $arguments->choice('distance_unit', ['km', 'mi']),
            'cost' => $this->kit->canSeeCosts($user, $vehicle) ? $this->number($user, $arguments, 'cost') : null,
            'fitted_by' => $arguments->string('fitted_by'),
            'notes' => $arguments->string('notes'),
        ]);
    }
}
