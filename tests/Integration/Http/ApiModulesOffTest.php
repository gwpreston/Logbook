<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 39's paths follow their module (spec.md §7.20): with it off,
 * each answers 404, whatever the id and method.
 */
final class ApiModulesOffTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testEveryNewPathOfASwitchedOffModuleIsNotFound(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $api = $this->api($app, $this->apiKey($app, $owner));
        $base = '/vehicles/' . $golf->id;

        $paths = [
            Feature::Fuel->value => [['GET', $base . '/fuel/1'], ['GET', '/reports/fuel']],
            Feature::Maintenance->value => [
                ['GET', $base . '/maintenance/1'],
                ['GET', $base . '/schedules'],
                ['GET', $base . '/schedules/1'],
            ],
            Feature::Compliance->value => [['GET', $base . '/documents/1']],
            Feature::Tyres->value => [['GET', $base . '/tyres/changes'], ['GET', $base . '/tyre-sets'], ['GET', '/tyre-sets']],
            Feature::Trips->value => [['GET', $base . '/trips/1']],
            Feature::Incidents->value => [['GET', $base . '/incidents/1']],
            Feature::Finance->value => [['GET', $base . '/finance/agreements']],
            Feature::Reminders->value => [
                ['POST', '/reminders/1/done'],
                ['POST', '/reminders/1/dismiss'],
                ['POST', '/reminders/1/reopen'],
            ],
        ];
        $this->assertNotFoundWithModuleOff($app, $api, $paths);
    }

    public function testEveryWriteOfASwitchedOffModuleIsNotFound(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $api = $this->api($app, $this->apiKey($app, $owner));
        $base = '/vehicles/' . $golf->id;

        // Phase 39.2: edits, deletes and the new writes.
        $paths = [
            Feature::Fuel->value => [['PATCH', $base . '/fuel/1'], ['DELETE', $base . '/fuel/1']],
            Feature::Maintenance->value => [['PATCH', $base . '/maintenance/1'], ['DELETE', $base . '/maintenance/1']],
            Feature::Compliance->value => [['PATCH', $base . '/documents/1'], ['DELETE', $base . '/documents/1']],
            Feature::Trips->value => [
                ['PATCH', $base . '/trips/1'],
                ['DELETE', $base . '/trips/1'],
                ['POST', '/journeys'],
                ['PATCH', '/journeys/1'],
                ['DELETE', '/journeys/1'],
            ],
            Feature::Stations->value => [
                ['PUT', '/stations/1/favourite'],
                ['DELETE', '/stations/1/favourite'],
                ['POST', '/fuel-prices/alerts'],
                ['PATCH', '/fuel-prices/alerts/1'],
                ['DELETE', '/fuel-prices/alerts/1'],
            ],
            Feature::Incidents->value => [['PATCH', $base . '/incidents/1'], ['DELETE', $base . '/incidents/1']],
            Feature::Reminders->value => [['PATCH', '/reminders/1'], ['DELETE', '/reminders/1']],
        ];
        $this->assertNotFoundWithModuleOff($app, $api, $paths);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, list<array{0: string, 1: string}>> $paths module → requests
     */
    private function assertNotFoundWithModuleOff(App $app, ApiClient $api, array $paths): void
    {
        $toggles = $this->service($app, FeatureToggles::class);
        foreach ($paths as $module => $requests) {
            $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f->value !== $module)));
            foreach ($requests as [$method, $path]) {
                $response = match ($method) {
                    'GET' => $api->get($path),
                    'POST' => $api->post($path, []),
                    'PATCH' => $api->patch($path, []),
                    'PUT' => $api->put($path),
                    default => $api->delete($path),
                };
                self::assertSame(404, $response->getStatusCode(), $module . ' off: ' . $method . ' ' . $path);
                self::assertSame('not_found', ApiClient::json($response)->get('code'));
            }
        }
    }
}
