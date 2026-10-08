<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Every finance agreement over the API (Phase 39.1, spec.md §7.20, §7.32):
 * the active one first, with payment events and quotes; never the
 * agreement number; 404 without §7.32's access.
 */
final class ApiFinanceAgreementsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testAgreementsCarryTheirEventsAndQuotesButNeverTheNumber(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->get('/vehicles/' . $golf->id . '/finance/new?type=hp');
        $created = $browser->post('/vehicles/' . $golf->id . '/finance/new', [
            'type' => 'hp',
            'lender' => 'Black Horse',
            'agreement_number' => 'HP-SECRET-0099',
            'started_on' => '2024-01-15',
            'first_payment_on' => '2024-02-15',
            'number_of_payments' => '48',
            'regular_payment' => '301.35',
            'cash_price' => '15000',
            'customer_deposit' => '3000',
            'apr' => '9.9',
            'count_in_costs' => '1',
        ]);
        self::assertSame(303, $created->getStatusCode());
        $api = $this->api($app, $this->apiKey($app, $this->owner($app)));
        $path = '/vehicles/' . $golf->id . '/finance/agreements';
        $id = ApiClient::json($api->get($path))->int('items', 0, 'id');
        $url = '/vehicles/' . $golf->id . '/finance/' . $id;
        $browser->post($url . '/payments', ['kind' => 'missed', 'due_on' => '2026-06-15']);
        $browser->post($url . '/quotes', ['quote_amount' => '7612.08', 'quoted_on' => '2026-07-10', 'valid_until' => '2026-07-31']);

        $response = $api->get($path);
        $list = ApiClient::json($response);
        self::assertSame([$id], $list->column('id', 'items'));
        self::assertSame('hp', $list->get('items', 0, 'type'));
        self::assertSame(['missed'], $list->column('kind', 'items', 0, 'events'));
        self::assertSame('2026-06-15', $list->get('items', 0, 'events', 0, 'due_on'));
        self::assertSame('7612.080', $list->get('items', 0, 'quotes', 0, 'amount'));
        self::assertStringNotContainsString('HP-SECRET-0099', (string) $response->getBody());

        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, true, false, new DateTimeImmutable('2026-07-01T00:00:00Z'));
        self::assertSame(404, $this->api($app, $this->apiKey($app, $viewer))->get($path)->getStatusCode());
    }
}
