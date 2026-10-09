<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Insights;

use DateTimeImmutable;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Location;

/**
 * A user's AI insights for one day (spec.md §6 AiInsightSet, §7.26 *AI
 * insights*): what the model found, the tool runs behind it and who
 * answered; or the error that stopped it. Cached for that day.
 */
final readonly class AiInsightSet
{
    /**
     * @param list<AiInsight> $insights
     * @param list<ToolRun> $runs
     */
    public function __construct(
        /** The user's local date it was made for, YYYY-MM-DD. */
        public string $day,
        public array $insights,
        public array $runs,
        public ?string $connectionName,
        public ?Location $location,
        public ?string $model,
        public ?ErrorCode $error,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isFor(string $day): bool
    {
        return $this->day === $day;
    }

    /**
     * The tool runs an insight came from.
     *
     * @return list<ToolRun>
     */
    public function sourcesOf(AiInsight $insight): array
    {
        return array_values(array_filter(array_map(fn (int $i): ?ToolRun => $this->runs[$i] ?? null, $insight->sources)));
    }

    /**
     * Only the insights whose sources are about vehicles the user can still
     * see (a share revoked since this morning drops them).
     *
     * @param list<int> $visible vehicle ids
     */
    public function visibleTo(array $visible): self
    {
        $kept = $this->kept(function (AiInsight $insight) use ($visible): bool {
            foreach ($this->sourcesOf($insight) as $run) {
                if (array_diff($run->vehicleIds, $visible) !== []) {
                    return false;
                }
            }

            return true;
        });
        // The vehicles the model named, only those still in sight.
        $insights = array_map(
            static fn (AiInsight $i): AiInsight => $i->withVehicles(array_values(array_intersect($i->vehicles, $visible))),
            $kept->insights,
        );

        return new self(
            $this->day,
            $insights,
            $this->runs,
            $this->connectionName,
            $this->location,
            $this->model,
            $this->error,
            $this->createdAt,
        );
    }

    /**
     * The set with only the insights $keep accepts (the reading filters,
     * spec.md §7.26: no repeats of a computed insight, no unmatched figure).
     *
     * @param callable(AiInsight): bool $keep
     */
    public function kept(callable $keep): self
    {
        return new self(
            $this->day,
            array_values(array_filter($this->insights, $keep)),
            $this->runs,
            $this->connectionName,
            $this->location,
            $this->model,
            $this->error,
            $this->createdAt,
        );
    }
}
