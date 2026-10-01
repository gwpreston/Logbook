<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;

/**
 * `draft_reading`: an odometer reading from a sentence.
 */
final class DraftReading extends DraftTool
{
    public function kind(): DraftKind
    {
        return DraftKind::Odometer;
    }

    protected function description(): string
    {
        return 'Draft an odometer (mileage) reading on its own, for the user to add. For a fill-up, service or '
            . 'document with a reading, use that tool instead: it writes the reading too.';
    }

    protected function properties(): array
    {
        return [
            'date' => self::dateProperty('The day of the reading'),
            'time' => ['type' => 'string', 'description' => 'HH:MM, only if the user said a time.'],
            'odometer' => self::numberProperty('The reading'),
            'distance_unit' => self::distanceUnitProperty(),
            'note' => ['type' => 'string'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        return self::given([
            'recorded_at' => $this->instant($user, $arguments) ?? $this->resolver->now()->format(DATE_ATOM),
            'odometer' => $this->number($user, $arguments, 'odometer'),
            'distance_unit' => $arguments->choice('distance_unit', ['km', 'mi']),
            'note' => $arguments->string('note'),
        ]);
    }
}
