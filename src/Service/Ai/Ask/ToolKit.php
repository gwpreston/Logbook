<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Vehicle\VehicleNotFound;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What every Ask tool shares: vehicles read through the access policy (one
 * the user can't see is "not found", exactly as the API), periods, the
 * cost rule, display strings and the words of a source line.
 */
final readonly class ToolKit
{
    /** Rows a list returns at most; the total is always given. */
    public const int LIST_CAP = 50;

    public const string NOT_FOUND = 'No vehicle with that id. Use an id from the vehicle list or find_vehicles.';

    public function __construct(
        private VehicleService $vehicles,
        private VehicleAccess $access,
        private ClaimReportService $claims,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
        public DisplayFormatter $format,
    ) {
    }

    public function today(User $user): DateTimeImmutable
    {
        return LocalTime::today($this->clock, $user->preferences->timeZone());
    }

    /**
     * @throws ToolError
     */
    public function vehicle(User $user, ToolArguments $arguments, string $key = 'vehicle'): Vehicle
    {
        $id = $arguments->int($key) ?? throw new ToolError(sprintf('"%s" is required: a vehicle id.', $key));

        return $this->vehicleById($user, $id);
    }

    /**
     * @throws ToolError
     */
    public function optionalVehicle(User $user, ToolArguments $arguments, string $key = 'vehicle'): ?Vehicle
    {
        return $arguments->has($key) ? $this->vehicle($user, $arguments, $key) : null;
    }

    /**
     * @throws ToolError
     */
    public function vehicleById(User $user, int $id): Vehicle
    {
        try {
            return $this->vehicles->get($user, $id);
        } catch (VehicleNotFound) {
            throw new ToolError(self::NOT_FOUND);
        }
    }

    /**
     * The vehicles asked for, or every vehicle the user can see (archived
     * ones included: last year's costs include a car sold since).
     *
     * @return array{list<Vehicle>, bool} the vehicles, and whether they were named
     * @throws ToolError
     */
    public function vehicles(User $user, ToolArguments $arguments, string $key = 'vehicles'): array
    {
        $ids = $arguments->ints($key) ?? ($arguments->has('vehicle') ? $arguments->ints('vehicle') : null);
        if ($ids === null) {
            return [$this->vehicles->listFleet($user, true), false];
        }

        return [array_map(fn (int $id): Vehicle => $this->vehicleById($user, $id), $ids), true];
    }

    /**
     * @return list<Vehicle>
     */
    public function fleet(User $user, bool $includeArchived = true): array
    {
        return $this->vehicles->listFleet($user, $includeArchived);
    }

    /**
     * @throws ToolError
     */
    public function period(User $user, ToolArguments $arguments, string $default = 'last_12_months'): AskPeriod
    {
        return AskPeriod::from(
            $arguments,
            $this->today($user),
            fn (DateTimeImmutable $date) => $this->claims->taxYearOf($user, $date),
            $default,
        );
    }

    public function canSeeCosts(User $user, Vehicle $vehicle): bool
    {
        return $this->access->can($user, VehicleAbility::ViewCosts, $vehicle);
    }

    public function currency(User $user, Vehicle $vehicle): string
    {
        return $this->vehicles->currencyFor($user, $vehicle);
    }

    /**
     * An amount as both forms: `{"amount": "1284.500", "currency": "GBP", "display": "£1,284.50"}`.
     *
     * @return array{amount: string, currency: string, display: string}
     */
    public function money(Money $money): array
    {
        return ['amount' => $money->toDecimal(2), 'currency' => $money->currency, 'display' => $this->format->money($money)];
    }

    /**
     * A distance in canonical km and as the user reads it.
     *
     * @return array{km: string, display: string}|null
     */
    public function distance(?string $km): ?array
    {
        return $km === null ? null : ['km' => $km, 'display' => $this->format->distance($km)];
    }

    /**
     * "1 Jan 2025 – 31 Dec 2025", "until 1 Oct 2026" (all time).
     */
    public function periodLabel(AskPeriod $period): string
    {
        if ($period->from === null) {
            return $this->t('ask.period.all_time', ['to' => $this->format->date($period->to)]);
        }

        return $this->t('ask.period.range', [
            'from' => $this->format->date($period->from),
            'to' => $this->format->date($period->to),
        ]);
    }

    /**
     * The vehicles' names, or "All vehicles" when none were named.
     *
     * @param list<Vehicle> $vehicles
     */
    public function vehiclesLabel(array $vehicles, bool $named): string
    {
        if (!$named) {
            return $this->t('ask.source.all_vehicles');
        }

        return implode(', ', array_map(static fn (Vehicle $v): string => $v->name(), $vehicles));
    }

    /**
     * A source line from its parts: "Costs · BMW 320d · 1 Jan – 31 Dec 2026 · by category".
     *
     * @param list<string|null> $parts
     */
    public function source(array $parts): string
    {
        return implode(' · ', array_values(array_filter($parts, static fn (?string $p): bool => $p !== null && $p !== '')));
    }

    /**
     * @param array<string, string|int|list<int>> $query
     */
    public function link(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn (mixed $v): bool => $v !== '' && $v !== []);

        return $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Summary fields every vehicle in a result carries.
     *
     * @return array{id: int, name: string}
     */
    public function vehicleRef(Vehicle $vehicle): array
    {
        return ['id' => $vehicle->id, 'name' => $vehicle->name()];
    }

    /**
     * A vehicle as the model's vehicle list and find_vehicles give it.
     *
     * @return array<string, mixed>
     */
    public function vehicleRow(Vehicle $vehicle): array
    {
        $data = $vehicle->data;

        return [
            'id' => $vehicle->id,
            'name' => $vehicle->name(),
            'type' => $data->type->value,
            'make' => $data->make,
            'model' => $data->model,
            'year' => $data->year,
            'registration' => $data->registration,
            'fuel_type' => $data->fuelType->value,
            'status' => $vehicle->status->value,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function t(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
