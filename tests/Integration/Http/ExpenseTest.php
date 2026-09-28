<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Clock\ClockInterface;

/**
 * The vehicle's Expenses tab: ad-hoc expenses (an amount of 0 is valid) and
 * the fuel, maintenance and document costs that roll up into it — each
 * exactly once. The owner uses UK units and GBP; "today" is 27 Sep 2026.
 */
final class ExpenseTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    private const array EXPENSE = [
        'spent_on' => '2026-09-14',
        'category' => 'parking',
        'amount' => '0',
        'note' => 'Free car park',
    ];

    public function testCrudWithAZeroAmount(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/expenses';

        $tab = self::body($browser->get($base));
        self::assertStringContainsString('No costs in this period', $tab);
        self::assertStringContainsString('aria-current="page">', $tab);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/export/expenses.csv"', $tab);

        self::assertStringContainsString('value="2026-09-27"', self::body($browser->get($base . '/new')), 'defaults to today');

        $created = $browser->post($base . '/new', self::EXPENSE);
        self::assertSame(303, $created->getStatusCode(), 'an amount of 0 saves');
        self::assertSame($base, $created->getHeaderLine('Location'));
        $list = self::body($browser->follow($created));
        self::assertStringContainsString('Expense saved.', $list);
        self::assertStringContainsString('Free car park', $list);
        self::assertStringContainsString('£0.00', $list);

        $repository = $this->service($app, ExpenseEntryRepository::class);
        $entry = $repository->listForVehicle($golf->id)[0];
        self::assertSame('0.000', $entry->data->amount);
        self::assertSame(ExpenseCategory::Parking, $entry->data->category);

        // Edit in place.
        $editPath = $base . '/' . $entry->id . '/edit';
        self::assertStringContainsString('value="Free car park"', self::body($browser->get($editPath)));
        $changes = ['amount' => '4.5', 'category' => 'tolls', 'note' => 'Severn bridge'];
        $updated = $browser->post($editPath, $changes + self::EXPENSE);
        self::assertSame($base, $updated->getHeaderLine('Location'));
        $list = self::body($browser->follow($updated));
        self::assertStringContainsString('Expense updated.', $list);
        self::assertStringContainsString('£4.50', $list);
        self::assertCount(1, $repository->listForVehicle($golf->id));
        self::assertSame('4.500', $repository->find($golf->id, $entry->id)?->data->amount);

        // Delete, with a confirmation page.
        self::assertStringContainsString('Delete this expense?', self::body($browser->get($base . '/' . $entry->id . '/delete')));
        $deleted = $browser->post($base . '/' . $entry->id . '/delete');
        self::assertSame($base, $deleted->getHeaderLine('Location'));
        self::assertStringContainsString('was deleted', self::body($browser->follow($deleted)));
        self::assertSame([], $repository->listForVehicle($golf->id));
        self::assertSame(404, $browser->get($editPath)->getStatusCode());
    }

    public function testValidationErrorsReRenderTheForm(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $invalid = ['spent_on' => '', 'amount' => '-1'] + self::EXPENSE;
        $response = $browser->post('/vehicles/' . $golf->id . '/expenses/new', $invalid);

        self::assertSame(422, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('This field is required.', $html);
        self::assertStringContainsString('Must be at least 0.', $html);
        self::assertStringContainsString('value="Free car park"', $html, 'input is kept');
        self::assertSame([], $this->service($app, ExpenseEntryRepository::class)->listForVehicle($golf->id));
    }

    public function testTheTabRollsUpEveryCostExactlyOnce(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $fill = $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '48000', '40', '60.00');
        $service = $this->maintenance($app, $golf, '2026-09-14', 'Annual service', '189.99');
        $this->maintenance($app, $golf, '2026-09-15', 'Tyre pressures (free)', '0');
        $policy = $this->document($app, $golf, ComplianceType::Insurance, '2026-09-01', '2027-08-31', '420', 'Admiral');
        $this->document($app, $golf, ComplianceType::Inspection, '2026-09-02', '2027-09-01', '0');
        $toll = $this->expense($app, $golf, '2026-09-20', '2.5', ExpenseCategory::Tolls, 'Dartford Crossing');
        $this->expense($app, $golf, '2025-01-10', '99', ExpenseCategory::Fines); // outside the last 12 months

        $html = self::body($browser->get('/vehicles/' . $golf->id . '/expenses'));

        self::assertStringContainsString('£672.49', $html, '60 + 189.99 + 420 + 2.50: every cost once');
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/fuel/' . $fill->id . '/edit"', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/maintenance/' . $service->id . '/edit"', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/documents/' . $policy->id . '/edit"', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/expenses/' . $toll->id . '/edit"', $html);
        self::assertStringNotContainsString('Tyre pressures (free)', $html, 'free work is history, not a cost');
        self::assertStringNotContainsString('£99.00', $html);
        self::assertSame(4, substr_count($html, 'class="list__amount tabular"'));
        // By group, with the shares.
        self::assertStringContainsString('62%', $html, 'insurance: £420 of £672.49');
        self::assertStringContainsString('Documents', $html);

        // "All time" brings the old fine in; "this month" is the same as the 12 months here.
        $tab = '/vehicles/' . $golf->id . '/expenses';
        self::assertStringContainsString('£771.49', self::body($browser->get($tab . '?range=all')));
        self::assertStringContainsString('£672.49', self::body($browser->get($tab . '?range=month')));
    }

    public function testExpensesOfAnotherVehicleOrAccountAreNotFound(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $bike = $this->vehicle($app, 'Honda', 'CB500', type: VehicleType::Bike);
        $entry = $this->expense($app, $golf, '2026-09-01', '3');

        self::assertSame(404, $browser->get('/vehicles/' . $bike->id . '/expenses/' . $entry->id . '/edit')->getStatusCode());

        $other = $this->service($app, UserRepository::class)->insert(
            'someone',
            'x',
            'Someone Else',
            $this->owner($app)->preferences,
            $this->service($app, ClockInterface::class)->now(),
        );
        $theirs = $this->service($app, VehicleService::class)
            ->create($other, new VehicleData(VehicleType::Car, 'Secret', 'Car', FuelType::Diesel));
        foreach (['/expenses', '/expenses/new', '/export/fuel.csv'] as $suffix) {
            self::assertSame(404, $browser->get('/vehicles/' . $theirs->id . $suffix)->getStatusCode(), $suffix);
        }
        self::assertSame(404, $browser->post('/vehicles/' . $theirs->id . '/expenses/new', self::EXPENSE)->getStatusCode());
    }
}
