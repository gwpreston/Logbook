<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Phase 39.1's paths follow their module (spec.md §7.20): with it off,
 * each answers 404, whatever the id.
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
        $toggles = $this->service($app, FeatureToggles::class);
        foreach ($paths as $module => $requests) {
            $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f->value !== $module)));
            foreach ($requests as [$method, $path]) {
                $response = $method === 'GET' ? $api->get($path) : $api->post($path, []);
                self::assertSame(404, $response->getStatusCode(), $module . ' off: ' . $method . ' ' . $path);
                self::assertSame('not_found', ApiClient::json($response)->get('code'));
            }
        }
    }
}
