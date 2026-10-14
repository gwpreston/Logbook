<?php

declare(strict_types=1);

namespace Logbook\Service\GettingStarted;

use Logbook\Domain\GettingStarted\StartStep;

/**
 * Where a user is in *Set up your first vehicle* (spec.md §7.9): the
 * vehicle the flow made, the steps saved and skipped, whether it was
 * dismissed, and the MOT expiry DVSA gave for the vehicle (carried to the
 * key dates step so DVSA is asked once).
 */
final readonly class GettingStartedProgress
{
    /**
     * @param list<string> $done StartStep values
     * @param list<string> $skipped StartStep values
     */
    public function __construct(
        public ?int $vehicleId = null,
        public array $done = [],
        public array $skipped = [],
        public bool $dismissed = false,
        public ?string $motExpiresOn = null,
    ) {
    }

    /**
     * @param mixed $stored a stored value, or null for none
     */
    public static function fromStored(mixed $stored): self
    {
        if (!is_array($stored)) {
            return new self();
        }
        $steps = static fn (mixed $list): array => is_array($list)
            ? array_values(array_filter($list, static fn (mixed $v): bool => is_string($v) && StartStep::tryFrom($v) !== null))
            : [];
        $vehicle = $stored['vehicle_id'] ?? null;
        $mot = $stored['mot_expires_on'] ?? null;

        return new self(
            is_int($vehicle) ? $vehicle : null,
            $steps($stored['done'] ?? null),
            $steps($stored['skipped'] ?? null),
            ($stored['dismissed'] ?? false) === true,
            is_string($mot) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mot) === 1 ? $mot : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'done' => $this->done,
            'skipped' => $this->skipped,
            'dismissed' => $this->dismissed,
            'mot_expires_on' => $this->motExpiresOn,
        ];
    }

    public function isDone(StartStep $step): bool
    {
        return in_array($step->value, $this->done, true);
    }

    public function isSkipped(StartStep $step): bool
    {
        return in_array($step->value, $this->skipped, true);
    }

    public function withVehicle(int $vehicleId, ?string $motExpiresOn): self
    {
        return new self($vehicleId, [StartStep::Vehicle->value], [], false, $motExpiresOn);
    }

    public function withDone(StartStep $step): self
    {
        return new self(
            $this->vehicleId,
            array_values(array_unique([...$this->done, $step->value])),
            array_values(array_diff($this->skipped, [$step->value])),
            $this->dismissed,
            $this->motExpiresOn,
        );
    }

    public function withSkipped(StartStep $step): self
    {
        return new self(
            $this->vehicleId,
            $this->done,
            array_values(array_unique([...$this->skipped, $step->value])),
            $this->dismissed,
            $this->motExpiresOn,
        );
    }

    public function dismissed(): self
    {
        return new self($this->vehicleId, $this->done, $this->skipped, true, $this->motExpiresOn);
    }
}
