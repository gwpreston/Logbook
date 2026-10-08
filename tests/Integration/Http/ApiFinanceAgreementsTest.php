<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Vehicle\VehicleService;
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
        $browser->post($url . '/quotes', [
            'quote_amount' => '7612.08',
            'quoted_on' => '2026-07-10',
            'valid_until' => '2026-07-31',
        ]);

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

    /**
     * HP at 9.9%: 48 payments of 301.35 from 15 Feb 2024, as the API takes it.
     *
     * @return array<string, mixed>
     */
    private static function hp(): array
    {
        return [
            'type' => 'hp',
            'lender' => 'Black Horse',
            'agreement_number' => 'HP-SECRET-0099',
            'started_on' => '2024-01-15',
            'first_payment_on' => '2024-02-15',
            'number_of_payments' => 48,
            'regular_payment' => '301.35',
            'cash_price' => 15000,
            'customer_deposit' => '3000',
            'apr' => '9.9',
            'set_purchase_price' => true,
        ];
    }

    public function testAnAgreementIsAddedEditedAndEndedAsThePagesDo(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $api = $this->api($app, $this->apiKey($app, $owner));
        $path = '/vehicles/' . $golf->id . '/finance/agreements';

        $created = $api->post($path, self::hp());
        self::assertSame(201, $created->getStatusCode(), self::body($created));
        $id = ApiClient::json($created)->int('entry', 'id');
        self::assertSame('active', ApiClient::json($created)->get('entry', 'status'));
        self::assertStringNotContainsString('HP-SECRET-0099', self::body($created), 'never returned');
        $vehicle = ApiClient::json($api->get('/vehicles/' . $golf->id));
        self::assertSame('15000.000', $vehicle->get('purchase_price'), 'set from the cash price');
        $again = $api->post($path, self::hp());
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('finance_active_exists', ApiClient::json($again)->get('code'));
        $invalid = $api->post($path, ['first_payment_on' => '2023-01-01'] + self::hp());
        self::assertSame(409, $invalid->getStatusCode(), 'one active agreement comes first');

        $agreement = $path . '/' . $id;
        $edited = $api->patch($agreement, ['lender' => 'Black Horse Finance']);
        self::assertSame(200, $edited->getStatusCode(), self::body($edited));
        self::assertSame('Black Horse Finance', ApiClient::json($edited)->get('entry', 'lender'));
        self::assertSame('9.900', ApiClient::json($edited)->get('entry', 'apr'), 'unsent fields stay');
        self::assertSame(412, $api->patch($agreement, ['notes' => 'x'], ['If-Match' => '"stale"'])->getStatusCode());
        self::assertSame(422, $api->patch($agreement, ['type' => 'loan'])->getStatusCode(), 'the type is its own');
        $bad = $api->patch($agreement, ['first_payment_on' => '2023-01-01']);
        self::assertSame('finance.error.first_before_start', ApiClient::json($bad)->get('errors', 'first_payment_on', 'key'));

        $missed = $api->post($agreement . '/payments', ['kind' => 'missed', 'due_on' => '2026-06-15']);
        self::assertSame(201, $missed->getStatusCode(), self::body($missed));
        self::assertSame(['missed'], ApiClient::json($missed)->column('kind', 'entry', 'events'));
        $twice = $api->post($agreement . '/payments', ['kind' => 'missed', 'due_on' => '2026-06-15']);
        self::assertSame('finance.error.mark', ApiClient::json($twice)->get('errors', 'due_on', 'key'), 'already missed');
        $late = $api->post(
            $agreement . '/payments',
            ['kind' => 'paid_late', 'due_on' => '2026-06-15', 'paid_on' => '2026-06-20'],
        );
        self::assertSame(['missed', 'paid_late'], ApiClient::json($late)->column('kind', 'entry', 'events'));
        $extra = $api->post($agreement . '/payments', ['kind' => 'extra', 'amount' => '500', 'paid_on' => '2026-07-01']);
        self::assertSame(201, $extra->getStatusCode());
        $future = $api->post($agreement . '/payments', ['kind' => 'extra', 'amount' => '500', 'paid_on' => '2026-08-01']);
        self::assertSame('finance.error.extra', ApiClient::json($future)->get('errors', 'paid_on', 'key'));
        $settlement = $api->post($agreement . '/payments', ['kind' => 'settlement', 'amount' => '1', 'paid_on' => '2026-07-01']);
        self::assertSame('validation.choice', ApiClient::json($settlement)->get('errors', 'kind', 'key'), '#299: through End');
        $missedId = ApiClient::json($late)->int('entry', 'events', 0, 'id');
        self::assertSame(412, $api->delete($agreement . '/payments/' . $missedId, ['If-Match' => '"stale"'])->getStatusCode());
        self::assertSame(204, $api->delete($agreement . '/payments/' . $missedId)->getStatusCode());
        $events = ApiClient::json($api->get($path))->column('kind', 'items', 0, 'events');
        self::assertSame(['extra'], $events, 'its paid-late mark went too');
        self::assertSame(404, $api->delete($agreement . '/payments/' . $missedId)->getStatusCode());

        $quote = $api->post(
            $agreement . '/quotes',
            ['quoted_on' => '2026-07-10', 'amount' => '7612.08', 'valid_until' => '2026-07-31'],
        );
        self::assertSame(201, $quote->getStatusCode(), self::body($quote));
        $quoteId = ApiClient::json($quote)->int('entry', 'quotes', 0, 'id');
        $backwards = $api->post(
            $agreement . '/quotes',
            ['quoted_on' => '2026-07-10', 'amount' => '1', 'valid_until' => '2026-07-01'],
        );
        self::assertSame('finance.error.quote', ApiClient::json($backwards)->get('errors', 'valid_until', 'key'));
        self::assertSame(412, $api->delete($agreement . '/quotes/' . $quoteId, ['If-Match' => '"stale"'])->getStatusCode());
        self::assertSame(204, $api->delete($agreement . '/quotes/' . $quoteId)->getStatusCode());
        self::assertSame(404, $api->delete($agreement . '/quotes/' . $quoteId)->getStatusCode());

        $wrong = $api->post($agreement . '/end', ['outcome' => 'handed_back', 'ended_on' => '2026-07-10']);
        self::assertSame('validation.choice', ApiClient::json($wrong)->get('errors', 'outcome', 'key'), 'not for HP');
        $ended = $api->post($agreement . '/end', ['outcome' => 'settled', 'ended_on' => '2026-07-10', 'settlement' => '7612.08']);
        self::assertSame(200, $ended->getStatusCode(), self::body($ended));
        self::assertSame('settled', ApiClient::json($ended)->get('entry', 'status'));
        self::assertContains('settlement', ApiClient::json($ended)->column('kind', 'entry', 'events'));
        $over = $api->post($agreement . '/end', ['outcome' => 'completed']);
        self::assertSame('finance_ended', ApiClient::json($over)->get('code'));
        self::assertSame(201, $api->post($path, self::hp())->getStatusCode(), 'none active now');
    }

    public function testFinanceWritesFollowSection732sAccess(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $path = '/vehicles/' . $golf->id . '/finance/agreements';

        $logger = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $logger->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-07-01T00:00:00Z'));
        self::assertSame(403, $this->api($app, $this->apiKey($app, $logger))->post($path, self::hp())->getStatusCode(), 'Manage');

        $api = $this->api($app, $this->apiKey($app, $owner));
        $id = ApiClient::json($api->post($path, self::hp()))->int('entry', 'id');
        $this->service($app, VehicleService::class)->archive($owner, $golf);
        self::assertSame('vehicle_archived', ApiClient::json($api->patch($path . '/' . $id, ['notes' => 'x']))->get('code'));
    }

    public function testSellingWithFinanceOwingSettlesFromTheSaleThroughArchive(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $api = $this->api($app, $this->apiKey($app, $owner));
        $path = '/vehicles/' . $golf->id . '/finance/agreements';
        $id = ApiClient::json($api->post($path, self::hp()))->int('entry', 'id');

        $archived = $api->post('/vehicles/' . $golf->id . '/archive', [
            'disposal' => 'sold',
            'sale_date' => '2026-07-10',
            'sale_price' => 9500,
            'settle_from_sale' => true,
            'settlement' => '7612.08',
        ]);
        self::assertSame(200, $archived->getStatusCode(), self::body($archived));
        self::assertSame('sold', ApiClient::json($archived)->get('entry', 'disposal'));
        self::assertSame('archived', ApiClient::json($archived)->get('entry', 'status'));
        $agreement = ApiClient::json($api->get($path));
        self::assertSame([$id], $agreement->column('id', 'items'));
        self::assertSame('settled', $agreement->get('items', 0, 'status'));
        self::assertSame('2026-07-10', $agreement->get('items', 0, 'ended_on'));
    }
}
