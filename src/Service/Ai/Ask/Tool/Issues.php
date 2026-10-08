<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IssueRepository;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Issue\IssueService;

/**
 * `issues(vehicles?, status?)` (spec.md §7.26, §7.37, Phase 40.2): the
 * issues the owner has noted, in their own words, with their status and
 * what fixed them. Open and watching by default. There is nothing here
 * about a cause: Logbook only records what was noticed.
 */
final readonly class Issues implements AskTool
{
    private const array STATUSES = ['open', 'watching', 'fixed', 'all'];

    public function __construct(
        private ToolKit $kit,
        private IssueService $issues,
        private IssueRepository $repository,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'issues';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Faults the owner has noticed on their vehicles and noted as issues, in their own words: title, '
            . 'description, status (open, watching, fixed), whether the owner ticked "Affects safety", when and at '
            . 'what mileage it was noticed, the look-again point while watching, and what fixed it. Safety issues '
            . 'first, then newest. Open and watching ones unless a status is given. These are the owner\'s notes, '
            . 'never a diagnosis: they say nothing about what causes a fault.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Vehicle ids; all when left out.',
                    ],
                    'status' => ['type' => 'string', 'enum' => self::STATUSES],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Issues);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        [$vehicles, $named] = $this->kit->vehicles($user, $arguments);
        $status = $arguments->string('status') ?? 'unresolved';
        if (!in_array($status, [...self::STATUSES, 'unresolved'], true)) {
            throw new ToolError('status must be one of: ' . implode(', ', self::STATUSES) . '.');
        }
        $statuses = match ($status) {
            'unresolved' => [IssueStatus::Open, IssueStatus::Watching],
            'all' => [],
            default => [IssueStatus::from($status)],
        };
        $byId = [];
        foreach ($vehicles as $vehicle) {
            $byId[$vehicle->id] = $vehicle;
        }
        $issues = $this->issues->listFor(array_keys($byId), $statuses);
        $shown = array_slice($issues, 0, ToolKit::LIST_CAP);
        $fixes = $this->repository->fixSummaries(array_map(static fn (Issue $i): int => $i->id, $shown));

        $rows = array_map(
            fn (Issue $issue): array => $this->row($issue, $byId[$issue->vehicleId], $fixes[$issue->id] ?? []),
            $shown,
        );
        $safety = count(array_filter($issues, static fn (Issue $i): bool => $i->data->affectsSafety));
        $label = $status === 'unresolved'
            ? $this->kit->t('issue.status.open') . ', ' . $this->kit->t('issue.status.watching')
            : $this->kit->t($status === 'all' ? 'issue.filter.all' : 'issue.status.' . $status);
        $one = $named && count($vehicles) === 1 ? $vehicles[0] : null;
        $query = $status === 'unresolved' ? [] : ['status' => $status];

        return new ToolResult(
            [
                'issues' => count($issues),
                'affecting_safety' => $safety,
                'rows' => $rows,
                'truncated' => count($issues) > count($shown),
                'note' => 'These are the owner\'s own notes. Never suggest what may be causing a fault; '
                    . 'suggest a qualified mechanic.',
            ],
            $this->kit->source([$this->kit->t('issue.title'), $label, $this->kit->vehiclesLabel($vehicles, $named)]),
            [(string) count($issues), (string) $safety],
            $one === null
                ? $this->kit->link('/issues')
                : $this->kit->link('/vehicles/' . $one->id . '/issues', $query),
            $named
                ? array_keys($byId)
                : array_values(array_unique(array_map(static fn (Issue $i): int => $i->vehicleId, $issues))),
        );
    }

    /**
     * @param list<array{title: string, date: \DateTimeImmutable}> $fixes
     * @return array<string, mixed>
     */
    private function row(Issue $issue, Vehicle $vehicle, array $fixes): array
    {
        $data = $issue->data;

        return [
            'vehicle' => $this->kit->vehicleRef($vehicle),
            'title' => $data->title,
            'description' => $data->description,
            'status' => $this->kit->t($issue->status()->labelKey()),
            'affects_safety' => $data->affectsSafety,
            'noticed_on' => $data->noticedOn->format('Y-m-d'),
            'noticed_on_display' => $this->kit->format->date($data->noticedOn),
            'odometer' => $this->kit->distance($data->odometerKm),
            'category' => $data->category?->value,
            'look_again_on' => $data->lookAgainOn?->format('Y-m-d'),
            'look_again_at' => $this->kit->distance($data->lookAgainKm),
            'fixed_on' => $issue->fixedOn?->format('Y-m-d'),
            'fixed_by' => array_map(
                fn (array $fix): string => $this->kit->format->date($fix['date']) . ' · ' . $fix['title'],
                $fixes,
            ),
            'fixed_without_record' => $issue->isFixed() && $issue->statusBeforeFix === null,
        ];
    }
}
