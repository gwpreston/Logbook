<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Reminder\CalendarFeed;
use Logbook\Service\Reminder\DueCounter;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Logbook\Tests\Support\VehicleRoutes;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\UploadedFile;

/**
 * A second owner inserted straight into the database (there is no way to
 * add one yet: Phase 19) stays invisible under the single-owner policy
 * (spec.md §5 *Access policy*): none of their vehicles, entries, reminders
 * or files appear in any list, report, feed or widget of the first, and
 * their ids answer 404 on every vehicle and reminder route.
 */
final class SecondOwnerTest extends AppTestCase
{
    use CostFixtures;
    use VehicleRoutes;

    private const string SECRET = 'Octavia';
    private const string SECRET_AMOUNT = '77.77';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testTheirGarageNeverShowsAndTheirIdsAreNotFound(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10000', '40', '55.00');

        $other = $this->otherOwner($app);
        $theirs = $this->service($app, VehicleService::class)
            ->create($other, new VehicleData(
                VehicleType::Car,
                'Skoda',
                self::SECRET,
                FuelType::Diesel,
                registration: 'OC71 XYZ',
            ));
        $theirFill = $this->fillUp($app, $theirs, '2026-09-12T08:00:00Z', '20000', '50', self::SECRET_AMOUNT);
        $this->maintenance($app, $theirs, '2026-09-14', self::SECRET . ' cambelt', '1200.00');
        $reminder = $this->service($app, ReminderService::class)->createManual($other, new ManualReminderData(
            $theirs->id,
            self::SECRET . ' MOT',
            new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')),
            30,
        ));
        $attachmentId = $this->theirDocumentWithAFile($app, $theirs);

        foreach ($this->pages() as $url) {
            $response = $browser->get($url);
            self::assertSame(200, $response->getStatusCode(), $url);
            $body = self::body($response);
            self::assertStringNotContainsString(self::SECRET, $body, $url);
            self::assertStringNotContainsString(self::SECRET_AMOUNT, $body, $url);
            self::assertStringNotContainsString('OC71 XYZ', $body, $url);
        }

        foreach ($this->vehicleRoutes($app) as $route) {
            $response = $this->requestRoute($browser, $route, $theirs, ['attachment' => $attachmentId]);
            self::assertSame(404, $response->getStatusCode(), $route->getPattern());
        }
        self::assertSame(404, $browser->get('/vehicles/' . $theirs->id . '/attachments/' . $attachmentId)->getStatusCode());
        // Nor through the owner's own vehicle: an entry or file must belong to the vehicle in the URL.
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/attachments/' . $attachmentId)->getStatusCode());
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/fuel/' . $theirFill->id . '/edit')->getStatusCode());
        self::assertSame(404, $browser->post('/vehicles/' . $golf->id . '/fuel/' . $theirFill->id . '/delete')->getStatusCode());
        self::assertSame(404, $browser->get('/reminders/' . $reminder->id . '/edit')->getStatusCode());
        self::assertSame(404, $browser->post('/reminders/' . $reminder->id . '/done')->getStatusCode());
        self::assertSame(404, $browser->post('/reminders/' . $reminder->id . '/delete')->getStatusCode());

        // Their vehicle chosen by id in a form or a filter is ignored, never used.
        self::assertStringNotContainsString(self::SECRET, self::body($browser->get('/reminders/new?vehicle=' . $theirs->id)));
        self::assertStringNotContainsString(self::SECRET, self::body($browser->get('/reports?vehicle=' . $theirs->id)));
        self::assertStringNotContainsString(self::SECRET, self::body($browser->get('/upcoming?vehicle=' . $theirs->id)));
        self::assertStringNotContainsString(self::SECRET, self::body($browser->get('/history?vehicle=' . $theirs->id)));
        $browser->get('/reminders/new');
        $refused = $browser->post('/reminders/new', [
            'vehicle_id' => (string) $theirs->id,
            'title' => 'Sneaky',
            'due_on' => '2026-11-01',
            'lead_time_days' => '14',
        ]);
        self::assertSame(422, $refused->getStatusCode());

        // Nor in what runs outside a page: the calendar feed and the due counts.
        $owner = $this->owner($app);
        self::assertStringNotContainsString(self::SECRET, $this->service($app, CalendarFeed::class)->render($owner));
        $due = $this->service($app, DueCounter::class)->counts($owner);
        self::assertSame(0, $due->total(), 'their reminder is due, but not counted');

        // A picker skips straight to the one vehicle the owner has: theirs is not a choice.
        $golfUrl = '/vehicles/' . $golf->id;
        self::assertSame($golfUrl . '/odometer/new', $browser->get('/log/new/odometer')->getHeaderLine('Location'));
        self::assertSame($golfUrl . '/fuel/new', $browser->get('/fuel/new')->getHeaderLine('Location'));

        // Control: they see their own, and not the owner's.
        $theirBrowser = $this->signedInAs($app, 'other');
        $theirGarage = self::body($theirBrowser->get('/garage'));
        self::assertStringContainsString(self::SECRET, $theirGarage);
        self::assertStringNotContainsString('/vehicles/' . $golf->id . '"', $theirGarage);
        self::assertSame(404, $theirBrowser->get('/vehicles/' . $golf->id)->getStatusCode());
    }

    /**
     * Every list, report, feed and widget of the signed-in owner.
     *
     * @return list<string>
     */
    private function pages(): array
    {
        return [
            '/',
            '/garage',
            '/garage?archived=1',
            '/history',
            '/upcoming',
            '/upcoming.csv',
            '/reports',
            '/reports/export.csv',
            '/reports/ownership',
            '/reports/ownership.csv',
            '/reminders',
            '/reminders/new',
            '/log/new',
            '/settings',
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function otherOwner(App $app): User
    {
        return $this->service($app, UserRepository::class)->insert(
            'other',
            $this->service($app, PasswordHasher::class)->hash(self::PASSWORD),
            'Sam Other',
            DisplayPreferences::defaults('en_GB', 'Europe/London', 'GBP'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function signedInAs(App $app, string $username): TestBrowser
    {
        $browser = new TestBrowser($app);
        $browser->get('/login');
        self::assertSame(303, $browser->post('/login', ['username' => $username, 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }

    /**
     * An insurance document with a PDF, added by its owner through the form.
     *
     * @param App<ContainerInterface> $app
     */
    private function theirDocumentWithAFile(App $app, Vehicle $theirs): int
    {
        $browser = $this->signedInAs($app, 'other');
        $browser->get('/vehicles/' . $theirs->id . '/documents/new');
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        file_put_contents($path, $pdf);
        $this->tempFiles[] = $path;

        $created = $browser->post('/vehicles/' . $theirs->id . '/documents/new', [
            'type' => 'insurance',
            'provider' => self::SECRET . ' Insurance',
            'starts_on' => '2026-09-01',
            'expires_on' => '2027-08-31',
            'cost' => '321.00',
        ], ['attachments' => [new UploadedFile($path, 'policy.pdf', 'application/pdf', strlen($pdf), UPLOAD_ERR_OK)]]);
        self::assertSame(303, $created->getStatusCode());

        $attachments = $this->service($app, AttachmentRepository::class)->listForVehicle($theirs->id);
        self::assertCount(1, $attachments);

        return $attachments[0]->id;
    }
}
