<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;

/**
 * `draft_fill_up`: a fill-up from a sentence. Any two of volume, price per
 * unit and total; Logbook works out the third. The fuel word ("E10",
 * "diesel", "rapid charge") is matched to the vehicle's codes.
 */
final class DraftFillUp extends DraftTool
{
    public function kind(): DraftKind
    {
        return DraftKind::Fuel;
    }

    protected function description(): string
    {
        return 'Draft a fill-up (or a charge) for the user to add. Use it when the user tells you they filled up '
            . 'or charged.';
    }

    protected function properties(): array
    {
        return [
            'date' => self::dateProperty('The day of the fill-up'),
            'time' => ['type' => 'string', 'description' => 'HH:MM, only if the user said a time.'],
            'odometer' => self::numberProperty('The odometer (mileage) reading'),
            'distance_unit' => self::distanceUnitProperty(),
            'volume' => self::numberProperty('How much went in'),
            'volume_unit' => [
                'type' => 'string',
                'enum' => ['l', 'gal_uk', 'gal_us', 'kwh'],
                'description' => 'Only if the user named the unit: litres, UK or US gallons, kWh.',
            ],
            'price_per_unit' => self::numberProperty('The price per litre, gallon or kWh'),
            'total_cost' => self::numberProperty('What it cost in all'),
            'fuel' => ['type' => 'string', 'description' => 'The fuel or grade in the user\'s words ("E10", '
                . '"super unleaded", "diesel", "rapid charge").'],
            'partial' => ['type' => 'boolean', 'description' => 'True if the user said they did not fill the tank.'],
            'missed_previous' => ['type' => 'boolean', 'description' => 'True if they said they forgot to log the last one.'],
            'station' => ['type' => 'string'],
            'notes' => ['type' => 'string'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $body = [
            'filled_at' => $this->instant($user, $arguments),
            'odometer' => $this->number($user, $arguments, 'odometer'),
            'distance_unit' => $arguments->choice('distance_unit', ['km', 'mi']),
            'volume' => $this->number($user, $arguments, 'volume'),
            'volume_unit' => $arguments->choice('volume_unit', ['l', 'gal_uk', 'gal_us', 'kwh']),
            'price_per_unit' => $this->number($user, $arguments, 'price_per_unit'),
            'total_cost' => $this->number($user, $arguments, 'total_cost'),
            'station' => $arguments->string('station'),
            'notes' => $arguments->string('notes'),
        ];
        foreach (['partial' => 'is_partial', 'missed_previous' => 'is_missed_previous'] as $argument => $field) {
            $value = $arguments->values[$argument] ?? null;
            $body[$field] = is_bool($value) ? $value : null;
        }
        $word = $arguments->string('fuel');
        if ($word !== null) {
            $fuel = $this->resolver->fuel($user, $vehicle, $word);
            if (is_string($fuel)) {
                throw new DraftQuestion($this->kit->t($fuel, ['words' => $word]));
            }
            $body['fuel'] = $fuel['fuel']?->value;
            $body['grade'] = $fuel['grade']?->value;
        }
        // A fill-up timed "now" needs no time to be sent: the writer's now is the same.
        if (($body['filled_at'] ?? null) === null) {
            $body['filled_at'] = $this->resolver->now()->format(DATE_ATOM);
        }

        return self::given($body);
    }
}
