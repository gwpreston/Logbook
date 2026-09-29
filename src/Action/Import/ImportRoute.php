<?php

declare(strict_types=1);

namespace Logbook\Action\Import;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Export\ExportModule;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for /vehicles/{id}/import/{module}...: the owner's
 * active vehicle and a module that is switched on, or a 404.
 */
final class ImportRoute
{
    /** Session key: the staged file of the import in progress. */
    public const string SESSION = 'import';

    /**
     * @param array<string, string> $args
     * @return array{0: Vehicle, 1: ExportModule}
     */
    public static function resolve(
        VehicleService $vehicles,
        FeatureToggles $features,
        ServerRequestInterface $request,
        array $args,
    ): array {
        $vehicle = VehicleRoute::vehicle($vehicles, $request, $args);
        $module = ExportModule::tryFrom($args['module'] ?? '') ?? throw new HttpNotFoundException($request);
        $feature = $module->feature();
        if (!$module->isImportable() || $vehicle->isArchived() || ($feature !== null && !$features->isEnabled($feature))) {
            throw new HttpNotFoundException($request);
        }

        return [$vehicle, $module];
    }
}
