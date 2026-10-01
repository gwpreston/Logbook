<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Service\Ai\Provider\ToolDefinition;

/**
 * `find_vehicles(query)`: the user's vehicles whose name, make, model or
 * registration contain every word of the query (spec.md §7.26).
 */
final readonly class FindVehicles implements AskTool
{
    public function __construct(private ToolKit $kit)
    {
    }

    public function name(): string
    {
        return 'find_vehicles';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Find the user\'s vehicles by name, make, model or registration. Returns every match; '
            . 'when more than one matches, ask the user which they mean.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to match, e.g. "BMW" or "AB12 CDE".'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return true;
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $query = $arguments->string('query') ?? throw new ToolError('"query" is required.');
        $words = preg_split('/\s+/u', self::fold($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matches = array_values(array_filter(
            $this->kit->fleet($user),
            static function (Vehicle $vehicle) use ($words): bool {
                $data = $vehicle->data;
                $haystack = self::fold(implode(' ', array_filter([
                    $vehicle->name(),
                    $data->make,
                    $data->model,
                    $data->variant,
                    $data->nickname,
                    $data->registration,
                    $data->registration === null ? null : str_replace(' ', '', $data->registration),
                    $data->year === null ? null : (string) $data->year,
                ])));
                foreach ($words as $word) {
                    if (!str_contains($haystack, $word)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return new ToolResult(
            [
                'query' => $query,
                'count' => count($matches),
                'vehicles' => array_map($this->kit->vehicleRow(...), array_slice($matches, 0, ToolKit::LIST_CAP)),
            ],
            $this->kit->source([$this->kit->t('ask.tool.find_vehicles'), '"' . $query . '"']),
            array_map(static fn (Vehicle $v): string => $v->name(), $matches),
            '/garage',
            array_map(static fn (Vehicle $v): int => $v->id, $matches),
        );
    }

    private static function fold(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
