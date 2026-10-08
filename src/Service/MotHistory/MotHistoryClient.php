<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Domain\MotHistory\MotVehicleRecord;

/**
 * A signed-in provider, for one fetch or one job run; its token is never
 * stored (spec.md §7.38 *Requests*).
 */
interface MotHistoryClient
{
    /**
     * @return MotVehicleRecord|null null when the provider has no record
     * @throws MotHistoryFailure
     */
    public function byRegistration(string $registration): ?MotVehicleRecord;

    /**
     * @return MotVehicleRecord|null null when the provider has no record
     * @throws MotHistoryFailure
     */
    public function byVin(string $vin): ?MotVehicleRecord;

    /**
     * A call that sends no vehicle but checks every credential: *Test* and
     * the keep-alive (#327).
     *
     * @throws MotHistoryFailure
     */
    public function ping(): void;
}
