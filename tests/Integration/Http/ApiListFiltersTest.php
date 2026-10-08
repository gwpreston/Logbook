<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The list filters of Phase 39.1 (spec.md §7.20): maintenance `category`
 * and `q` (every word, any case, as Ask searches), documents `type` and
 * `current` (in force today in the key user's time zone), and 400 for a
 * value that can't be read. Paging still applies after filtering.
 */
final class ApiListFiltersTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner(
            $this->app,
            preferences: DisplayPreferences::defaults('en_GB', 'Pacific/Auckland', 'GBP'),
        );
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    private function record(
        string $date,
        MaintenanceCategory $category,
        string $title,
        ?string $vendor = null,
        ?string $notes = null,
    ): int {
        return $this->service($this->app, MaintenanceService::class)->create($this->golf, new MaintenanceEntryData(
            new DateTimeImmutable($date, new DateTimeZone('UTC')),
            $category,
            $title,
            '10.00',
            null,
            $vendor,
            $notes,
        ), new DateTimeZone('UTC'))->id;
    }

    public function testMaintenanceFiltersByCategoryAndEveryWord(): void
    {
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $service = $this->record('2026-01-10', MaintenanceCategory::Service, 'Annual service', 'Main Dealer');
        $brakes = $this->record('2026-03-02', MaintenanceCategory::Brakes, 'Front pads', 'Kwik Fit', 'Squeal at low speed');
        $oil = $this->record('2026-06-20', MaintenanceCategory::Oil, 'Oil top-up', 'Main dealer');
        $path = '/vehicles/' . $this->golf->id . '/maintenance';

        self::assertSame([$brakes], $this->ids($path . '?category=brakes'));
        self::assertSame([$oil, $service], $this->ids($path . '?q=MAIN%20dealer'), 'every word, any case');
        self::assertSame([$brakes], $this->ids($path . '?q=squeal'), 'the description too');
        self::assertSame([], $this->ids($path . '?category=service&q=pads'));
        self::assertSame([$oil, $brakes, $service], $this->ids($path . '?q=%20%20'), 'blank text is no filter');

        $first = ApiClient::json($this->api->get($path . '?q=dealer&limit=1'));
        self::assertSame([$oil], $first->column('id', 'items'));
        $next = $first->get('next');
        self::assertIsString($next);
        self::assertStringContainsString('q=dealer', $next, 'the next page keeps the filter');

        $bad = $this->api->get($path . '?category=wipers');
        self::assertSame(400, $bad->getStatusCode());
        self::assertSame('invalid_parameter', ApiClient::json($bad)->get('code'));
    }

    public function testDocumentsFilterByTypeAndWhatIsInForceInTheKeyUsersZone(): void
    {
        // 2026-09-30 11:30 UTC is already 1 October in Auckland.
        $this->pinClock($this->app, '2026-09-30T11:30:00Z');
        $golf = $this->golf;
        $expiredToday = $this->document($this->app, $golf, ComplianceType::Insurance, '2025-10-01', '2026-09-30', '400')->id;
        $renewal = $this->document($this->app, $golf, ComplianceType::Insurance, '2026-10-01', '2027-09-30', '420')->id;
        $mot = $this->document($this->app, $golf, ComplianceType::Inspection, '2026-05-01', '2027-04-30', '54.85')->id;
        $starts = $this->document($this->app, $golf, ComplianceType::Registration, '2026-12-01', '2027-11-30', '190')->id;
        $path = '/vehicles/' . $this->golf->id . '/documents';

        self::assertEqualsCanonicalizing([$expiredToday, $renewal], $this->ids($path . '?type=insurance'));
        self::assertEqualsCanonicalizing([$renewal, $mot], $this->ids($path . '?current=1'), 'not expired, not to come');
        self::assertSame([$renewal], $this->ids($path . '?type=insurance&current=true'));
        self::assertCount(4, $this->ids($path . '?current=0'));
        self::assertNotContains($starts, $this->ids($path . '?current=1'));

        self::assertSame(400, $this->api->get($path . '?current=yes')->getStatusCode());
        self::assertSame(400, $this->api->get($path . '?type=passport')->getStatusCode());
    }

    /**
     * @return list<int>
     */
    private function ids(string $path): array
    {
        $response = $this->api->get($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $page = ApiClient::json($response);
        $items = $page->get('items');
        self::assertIsArray($items);

        return array_map(static fn (int $index): int => $page->int('items', $index, 'id'), array_keys($items));
    }
}
