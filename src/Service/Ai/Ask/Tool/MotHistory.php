<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\MotHistory\MotDefect;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Domain\User\User;
use Logbook\Repository\MotTestRepository;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotReview;

/**
 * `mot_history(vehicle)` (spec.md §7.26, §7.38): the MOT tests fetched
 * from DVSA for one vehicle, with their mileage, defects by type, what was
 * made from them, the recall state and when it was fetched. Only while MOT
 * history is on. Never fetches.
 */
final readonly class MotHistory implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private MotHistoryConfig $config,
        private MotTestRepository $tests,
        private MotReview $review,
    ) {
    }

    public function name(): string
    {
        return 'mot_history';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'A vehicle\'s official UK MOT history as fetched from DVSA: each test\'s date, result, expiry and '
            . 'mileage, its defects and advisories by type (advisory, minor, major, dangerous, fail), the issue '
            . 'or document each became, whether a manufacturer recall is outstanding, and when it was fetched. '
            . 'Empty when the owner has not fetched it. Quote the attribution with the answer.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id.'],
                ],
                'required' => ['vehicle'],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->config->enabled();
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $vehicle = $this->kit->vehicle($user, $arguments);
        $provider = $this->config->provider();
        $state = $this->tests->state($vehicle->id);
        $tests = $this->tests->listForVehicle($vehicle->id);
        $shown = array_slice($tests, 0, ToolKit::LIST_CAP);
        $documents = $this->review->documentsFor($vehicle, $shown);
        $licence = $provider?->licence();
        $attribution = $licence === null ? null : trim($this->kit->t($licence->attributionKey) . ' ' . $licence->name . '.');
        $latest = $tests[0] ?? null;

        return new ToolResult(
            [
                'vehicle' => $this->kit->vehicleRef($vehicle),
                'fetched' => $state->fetchedAt !== null,
                'fetched_on' => $state->fetchedAt === null ? null : $this->kit->format->instantDate($state->fetchedAt),
                'recall' => $state->recall === null ? null : $this->kit->t('mot_history.recall.' . $state->recall->value),
                'first_mot_due_on' => $state->firstDueOn?->format('Y-m-d'),
                'tests' => array_map(fn (MotTest $test): array => $this->row($test, $documents[$test->id] ?? null), $shown),
                'total_count' => count($tests),
                'truncated' => count($tests) > count($shown),
                'attribution' => $attribution,
            ],
            $this->kit->source([$this->kit->t('mot_history.page.title'), $vehicle->name(), $attribution]),
            $latest === null ? [] : [
                $this->kit->t('mot_history.result.' . $latest->result->value),
                $this->kit->format->instantDate($latest->completedAt),
            ],
            '/vehicles/' . $vehicle->id . '/mot-history',
            [$vehicle->id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MotTest $test, ?int $documentId): array
    {
        return [
            'date' => $test->completedAt->format('Y-m-d'),
            'date_display' => $this->kit->format->instantDate($test->completedAt),
            'result' => $test->result->value,
            'expires_on' => $test->expiryOn?->format('Y-m-d'),
            'odometer' => $this->kit->distance($test->odometerKm),
            'odometer_not_read' => $test->odometerKm === null,
            'defects' => array_map(static fn (MotDefect $defect): array => [
                'type' => $defect->type->value,
                'dangerous' => $defect->dangerous,
                'text' => $defect->text,
                'issue_id' => $defect->issueId,
            ], $test->defects),
            'document_id' => $documentId,
        ];
    }
}
