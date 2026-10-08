<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Domain\MotHistory\MotVehicleRecord;
use Logbook\Domain\Vehicle\FuelType;

/**
 * *Look up* on the add-vehicle form (spec.md §7.38 *Look up on add*, #326,
 * #330): DVSA's make, model, fuel type, first registration and, for a
 * vehicle with no tests, first MOT due, for the form's blank fields. Nothing
 * is stored, and the new vehicle's MOT history is not enabled by it.
 */
final readonly class VehicleLookup
{
    /** The form's fields it may fill; fuel only while it is still the form's default. */
    public const array FIELDS = ['make', 'model', 'fuel_type', 'first_registered_on', 'first_inspection_due_on'];

    public function __construct(
        private MotHistoryConfig $config,
        private MotHistoryCalls $calls,
    ) {
    }

    public function available(): bool
    {
        return $this->config->enabled();
    }

    /**
     * @param array<string, string> $values the form as posted
     * @return array{fields: array<string, string>, message: string, params: array<string, string>}
     *   what to fill, and a message key with its parameters
     */
    public function lookUp(array $values): array
    {
        $provider = $this->config->provider();
        $raw = $values['registration'] ?? '';
        $plate = VehicleIdentifier::registration($raw);
        if ($provider === null) {
            return ['fields' => [], 'message' => 'mot_history.unavailable.off', 'params' => []];
        }
        if ($plate === null) {
            return ['fields' => [], 'message' => 'mot_history.lookup.no_registration', 'params' => []];
        }
        try {
            $record = $this->calls->run(
                $provider,
                static fn (MotHistoryClient $client): ?MotVehicleRecord => $client->byRegistration($plate),
            );
        } catch (MotHistoryFailure $failure) {
            return ['fields' => [], 'message' => $failure->error->messageKey(), 'params' => $failure->parameters];
        }
        if ($record === null) {
            return ['fields' => [], 'message' => 'mot_history.fetch.not_found', 'params' => ['registration' => trim($raw)]];
        }

        $found = array_filter([
            'make' => $record->make === null ? null : self::title($record->make),
            'model' => $record->model === null ? null : self::title($record->model),
            'fuel_type' => self::fuel($record->fuelType)?->value,
            'first_registered_on' => ($record->registeredOn ?? $record->firstUsedOn)?->format('Y-m-d'),
            'first_inspection_due_on' => $record->tests === [] ? $record->firstDueOn?->format('Y-m-d') : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
        $fields = [];
        foreach ($found as $name => $value) {
            $current = trim($values[$name] ?? '');
            $blank = $name === 'fuel_type' ? ($current === '' || $current === FuelType::Petrol->value) : $current === '';
            if ($blank && $current !== $value) {
                $fields[$name] = $value;
            }
        }

        return [
            'fields' => $fields,
            'message' => $fields === [] ? 'mot_history.lookup.nothing_new' : 'mot_history.lookup.filled',
            'params' => ['count' => (string) count($fields)],
        ];
    }

    /**
     * DVSA's fuel names (spec.md §7.38 *Look up on add*); unknown ones fill nothing.
     */
    public static function fuel(?string $dvsa): ?FuelType
    {
        $name = strtolower(trim((string) $dvsa));

        return match (true) {
            $name === 'petrol' => FuelType::Petrol,
            $name === 'diesel' => FuelType::Diesel,
            in_array($name, ['electric', 'electricity'], true) => FuelType::Electric,
            str_starts_with($name, 'hybrid electric') => FuelType::Hybrid,
            in_array($name, ['lpg', 'petrol/lpg', 'gas bi-fuel'], true) => FuelType::Lpg,
            default => null,
        };
    }

    /**
     * DVSA writes in capitals: "VOLKSWAGEN" → "Volkswagen", "MERCEDES-BENZ"
     * → "Mercedes-Benz", while short words stay ("BMW", "GOLF MATCH TSI" →
     * "Golf Match TSI"). Mixed case is kept as it is.
     */
    private static function title(string $value): string
    {
        if ($value !== mb_strtoupper($value)) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\p{L}{4,}/u',
            static fn (array $word): string => mb_convert_case(mb_strtolower($word[0]), MB_CASE_TITLE),
            $value,
        );
    }
}
