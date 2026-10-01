<?php

declare(strict_types=1);

namespace Logbook\Service\Mcp;

use JsonException;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Service\Api\ApiReader;
use Logbook\Service\Api\KeyHolder;
use Logbook\Service\Trip\TripSettingsStore;
use Logbook\Service\Vehicle\VehicleService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The MCP resources (spec.md §7.28 *Resources*), all JSON, as the key's
 * user sees them: their vehicles, their preferences, and one vehicle's
 * summary (the `vehicle_summary` tool's result). A vehicle they can't see is
 * not found, like one that doesn't exist.
 */
final readonly class McpResources
{
    public const string VEHICLES = 'logbook://vehicles';
    public const string ME = 'logbook://me';
    public const string SUMMARY_TEMPLATE = 'logbook://vehicles/{id}/summary';
    private const string SUMMARY = '#^logbook://vehicles/([1-9][0-9]{0,9})/summary$#';
    private const string MIME = 'application/json';

    public function __construct(
        private VehicleService $vehicles,
        private ApiReader $reader,
        private TripSettingsStore $trips,
        private ToolRegistry $registry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return [
            $this->describe(self::VEHICLES, 'vehicles'),
            $this->describe(self::ME, 'me'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function templates(): array
    {
        return [[
            'uriTemplate' => self::SUMMARY_TEMPLATE,
            'name' => 'vehicle_summary',
            'title' => $this->translator->trans('mcp.resource.vehicle_summary.title'),
            'description' => $this->translator->trans('mcp.resource.vehicle_summary.description'),
            'mimeType' => self::MIME,
        ]];
    }

    /**
     * The resource's contents, as `resources/read` returns them.
     *
     * @return list<array<string, mixed>>
     * @throws McpError when there is no such resource for this user
     */
    public function read(KeyHolder $holder, string $uri, bool $modern): array
    {
        $user = $holder->user;
        $data = match (true) {
            $uri === self::VEHICLES => array_map(static fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'name' => $vehicle->name(),
                'registration' => $vehicle->data->registration,
                'type' => $vehicle->data->type->value,
                'make' => $vehicle->data->make,
                'model' => $vehicle->data->model,
                'year' => $vehicle->data->year,
                'fuel_type' => $vehicle->data->fuelType->value,
                'status' => $vehicle->status->value,
            ], $this->vehicles->listFleet($user, true)),
            $uri === self::ME => [
                ...$this->reader->me($user, $holder->key),
                'tax_year_start' => $this->trips->for($user)->taxYearStart,
            ],
            preg_match(self::SUMMARY, $uri, $m) === 1 => $this->summary($holder, (int) $m[1], $uri, $modern),
            default => throw self::notFound($uri, $modern),
        };

        try {
            $text = json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new McpError(McpError::INTERNAL_ERROR, 'The resource could not be read.');
        }

        return [['uri' => $uri, 'mimeType' => self::MIME, 'text' => $text]];
    }

    /**
     * @return array<string, mixed>
     * @throws McpError
     */
    private function summary(KeyHolder $holder, int $vehicleId, string $uri, bool $modern): array
    {
        $run = $this->registry->run($holder->user, new ToolCall('mcp', 'vehicle_summary', ['vehicle' => $vehicleId]));
        if ($run->result === null) {
            throw self::notFound($uri, $modern);
        }

        return $run->result->data;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $uri, string $name): array
    {
        return [
            'uri' => $uri,
            'name' => $name,
            'title' => $this->translator->trans('mcp.resource.' . $name . '.title'),
            'description' => $this->translator->trans('mcp.resource.' . $name . '.description'),
            'mimeType' => self::MIME,
        ];
    }

    private static function notFound(string $uri, bool $modern): McpError
    {
        return new McpError(
            $modern ? McpError::INVALID_PARAMS : McpError::LEGACY_RESOURCE_NOT_FOUND,
            'Resource not found',
            200,
            ['uri' => mb_substr($uri, 0, 200)],
        );
    }
}
