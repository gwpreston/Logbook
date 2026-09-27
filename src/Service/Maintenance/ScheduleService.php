<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\MaintenanceScheduleRepository;
use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Support\Database\Transaction;
use Psr\Clock\ClockInterface;

/**
 * Recurring maintenance schedules (spec.md §7.4).
 *
 * Each schedule's last-done and next-due points are recomputed and stored
 * whenever the schedule or an entry completing it changes (recompute()), so
 * `next_due_on` / `next_due_km` are always current and queryable. How urgent
 * each one is depends on today and the odometer, so that is judged on read
 * (states()).
 *
 * Callers pass a Vehicle already resolved for the signed-in owner.
 */
final readonly class ScheduleService
{
    public function __construct(
        private MaintenanceScheduleRepository $schedules,
        private MaintenanceEntryRepository $entries,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<MaintenanceSchedule> in creation order
     */
    public function list(Vehicle $vehicle): array
    {
        return $this->schedules->listForVehicle($vehicle->id);
    }

    /**
     * Every schedule with its due state, most urgent first.
     *
     * @param DateTimeImmutable $today the owner's calendar date
     * @return list<ScheduleState>
     */
    public function states(Vehicle $vehicle, DateTimeImmutable $today, OdometerHistory $odometer): array
    {
        $current = $odometer->latest()?->readingKm;
        $perDay = $odometer->averageKmPerDay();

        $states = array_map(
            static fn (MaintenanceSchedule $s): ScheduleState => new ScheduleState(
                $s,
                DueState::evaluate($s->nextDue, $today, $current, $perDay),
            ),
            $this->list($vehicle),
        );

        usort($states, static fn (ScheduleState $a, ScheduleState $b): int
            => ($a->due->status->urgency() <=> $b->due->status->urgency())
            ?: self::compareDue($a->due, $b->due)
            ?: $a->schedule->id <=> $b->schedule->id);

        return $states;
    }

    /**
     * @throws MaintenanceScheduleNotFound
     */
    public function get(Vehicle $vehicle, int $id): MaintenanceSchedule
    {
        return $this->schedules->find($vehicle->id, $id)
            ?? throw new MaintenanceScheduleNotFound(sprintf('Maintenance schedule %d not found.', $id));
    }

    public function create(Vehicle $vehicle, MaintenanceScheduleData $data): MaintenanceSchedule
    {
        $id = $this->transaction->run(function () use ($vehicle, $data): int {
            $id = $this->schedules->insert($vehicle->id, $data, $this->clock->now());
            $this->recompute($vehicle, $id);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(Vehicle $vehicle, MaintenanceSchedule $schedule, MaintenanceScheduleData $data): MaintenanceSchedule
    {
        $this->transaction->run(function () use ($vehicle, $schedule, $data): void {
            $this->schedules->update($vehicle->id, $schedule->id, $data, $this->clock->now());
            $this->recompute($vehicle, $schedule->id);
        });

        return $this->get($vehicle, $schedule->id);
    }

    /**
     * Delete a schedule. Entries that completed it stay in the history.
     */
    public function delete(Vehicle $vehicle, MaintenanceSchedule $schedule): void
    {
        $this->transaction->run(function () use ($vehicle, $schedule): void {
            $this->entries->unlinkSchedule($vehicle->id, $schedule->id);
            $this->schedules->delete($vehicle->id, $schedule->id);
        });
    }

    /**
     * Recalculate and store a schedule's last-done and next-due points from
     * its entries. Call (inside the transaction) after anything that could
     * move them.
     */
    public function recompute(Vehicle $vehicle, int $scheduleId): void
    {
        $schedule = $this->schedules->find($vehicle->id, $scheduleId);
        if ($schedule === null) {
            return;
        }

        $data = $schedule->data;
        $lastDone = ScheduleCalculator::lastDone($data, $this->entries->listForSchedule($vehicle->id, $scheduleId));
        $nextDue = ScheduleCalculator::nextDue($lastDone, $data->intervalKm, $data->intervalMonths);

        $this->schedules->setComputed($vehicle->id, $scheduleId, $lastDone, $nextDue);
    }

    /**
     * By due date (unknown last), then by distance left.
     */
    private static function compareDue(DueState $a, DueState $b): int
    {
        if ($a->dueOn !== null || $b->dueOn !== null) {
            return match (true) {
                $a->dueOn === null => 1,
                $b->dueOn === null => -1,
                default => $a->dueOn <=> $b->dueOn,
            };
        }

        return (float) ($a->kmLeft ?? INF) <=> (float) ($b->kmLeft ?? INF);
    }
}
