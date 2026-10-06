<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Dashboard\DashboardLayoutStore;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\QueryCounter;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The reminders calendar and the dashboard's Calendar widget, end to end
 * (spec.md §7.6 *Calendar view*, §7.8 *Calendar*, Phase 34.3). Every page
 * here is plain HTML: nothing needs JavaScript.
 */
final class ReminderCalendarTest extends ReminderTestCase
{
    /** A Sunday in September; the owner is in Europe/London (en_GB). */
    private const string NOW = '2026-09-27T10:00:00Z';

    public function testTheCalendarShowsWhatTheListShowsSourceBySource(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $this->document($app, $golf, '2026-09-20', ComplianceType::Inspection, null, 'MOT');
        $this->schedule($app, $golf, 'Annual service', '2025-12-01');
        $this->manual($browser, $golf, 'Pay road tax', '2026-10-01');

        $list = self::body($browser->get('/reminders'));
        $reminders = $this->reminders($app);
        self::assertCount(4, $reminders);

        $placed = [];
        foreach ($reminders as $reminder) {
            self::assertStringContainsString('/reminders/' . $reminder->id . '/done', $list);
            $due = $reminder->dueOn;
            self::assertNotNull($due);
            $page = $browser->get('/reminders/calendar?month=' . $due->format('Y-m'));
            self::assertSame(200, $page->getStatusCode());
            $html = self::body($page);
            $day = self::day($html, $due->format('Y-m-d'));
            self::assertStringContainsString(self::label($reminder), $day, $reminder->source->value . ' sits on its due date');
            $placed[$due->format('Y-m')] = substr_count($html, 'class="cal-item ');
        }
        self::assertSame(4, array_sum($placed), 'each reminder on exactly one day, nothing else');

        // The default is today's month, with the switch marking where we are.
        $html = self::body($browser->get('/reminders/calendar'));
        self::assertStringContainsString('<h2 class="cal__month" id="cal-month">September 2026</h2>', $html);
        self::assertMatchesRegularExpression('#href="/reminders/calendar" aria-current="page"#', $html);
        self::assertMatchesRegularExpression('#href="/reminders" aria-current="page"#', $list);
        self::assertStringContainsString('aria-current="date"', self::day($html, '2026-09-27'));
        // The sidebar does not change: the calendar is part of Reminders.
        self::assertStringContainsString('class="nav-link" href="/reminders" aria-current="page"', $html);
    }

    public function testWeeksStartOnTheLocalesFirstDayAndCarryTheirNumber(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->vehicle($app);

        $html = self::body($browser->get('/reminders/calendar?month=2026-12'));
        self::assertStringContainsString('Week 53', $html, 'en_GB: ISO-style week numbers');
        self::assertStringNotContainsString('Week 1<', $html);
        // Monday first: 30 November fills the first row, dimmed and empty.
        self::assertMatchesRegularExpression(
            '#cal__weekdays" aria-hidden="true">\s*<span class="cal__weeknum-head"></span>\s*<span>Mon</span>#',
            $html,
        );
        self::assertStringContainsString(
            '<li class="cal__day cal__day--out" aria-hidden="true"><p class="cal__date"><span class="cal__daynum">30</span>',
            $html,
        );
        self::assertStringContainsString('<span class="cal__weekday">Tuesday </span><span class="cal__daynum">1</span>', $html);

        $this->createMember($app, 'casey', new DisplayPreferences(
            'en_US',
            'America/New_York',
            DistanceUnit::Mile,
            VolumeUnit::UsGallon,
            ConsumptionUnit::MpgUs,
            'USD',
        ));
        $html = self::body($this->browserFor($app, 'casey')->get('/reminders/calendar?month=2026-12'));
        self::assertMatchesRegularExpression('#<span class="cal__weeknum-head"></span>\s*<span>Sun</span>#', $html);
        self::assertStringContainsString('Week 1<', $html, 'en_US: the week of 1 January is week 1');
        self::assertStringNotContainsString('Week 53', $html);
    }

    public function testOverdueStripRemindersWithNoDateAndClosedOnes(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-09-20', ComplianceType::Inspection, null, 'MOT');
        $this->document($app, $golf, '2026-03-02', ComplianceType::Registration, null, 'Road tax');
        $this->manual($browser, $golf, 'Chain and sprockets', null, '48000');
        $this->manual($browser, $golf, 'Wash the car', '2026-10-05');
        $browser->get('/reminders');
        $wash = $this->find($app, 'Wash the car');
        $browser->post('/reminders/' . $wash->id . '/dismiss');

        $html = self::body($browser->get('/reminders/calendar?month=2026-10'));
        self::assertStringContainsString('2 reminders are overdue', $html);
        $roadTax = 'href="/reminders/calendar?month=2026-03&amp;day=2026-03-02#cal-day">Road tax</a>';
        self::assertStringContainsString($roadTax, $html, 'most overdue first');
        self::assertStringContainsString('href="/reminders/calendar?month=2026-09&amp;day=2026-09-20#cal-day">MOT</a>', $html);
        self::assertStringContainsString('See them all in the list', $html);
        $september = self::body($browser->get('/reminders/calendar?month=2026-09'));
        self::assertStringContainsString('MOT', self::day($september, '2026-09-20'), 'and on its own date');

        self::assertStringContainsString('Not on the calendar yet (1)', $html);
        self::assertStringContainsString('Chain and sprockets', $html);

        // Done and dismissed: shown, muted, in words, unless hidden.
        $day = self::day($html, '2026-10-05');
        self::assertStringContainsString('cal-item--dismissed cal-item--closed', $day);
        self::assertStringContainsString('<span>Dismissed</span>', $day);
        self::assertStringContainsString('href="/reminders/calendar?month=2026-10&amp;closed=0">', $html, 'the hide link');
        $hidden = self::body($browser->get('/reminders/calendar?month=2026-10&closed=0'));
        self::assertStringNotContainsString('Wash the car', $hidden);
        self::assertStringContainsString('Show done and dismissed', $hidden);
        $next = 'href="/reminders/calendar?closed=0&amp;month=2026-11"';
        self::assertStringContainsString($next, $hidden, 'paging keeps the choice');
        self::assertStringContainsString('2 reminders are overdue', $hidden, 'the strip counts open ones either way');
    }

    public function testABusyDayOpensInFullWithItsActionsAndAddReminder(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $title) {
            $this->manual($browser, $golf, $title, '2026-10-15');
        }
        $browser->get('/reminders');
        $done = $this->find($app, 'Two');
        $browser->post('/reminders/' . $done->id . '/done');

        $html = self::body($browser->get('/reminders/calendar?month=2026-10'));
        $day = self::day($html, '2026-10-15');
        self::assertSame(3, substr_count($day, 'class="cal-item '));
        self::assertStringContainsString('+2 more', $day);
        self::assertStringNotContainsString('cal-item--done', $day, 'the closed one is pushed out, never an open one');
        self::assertStringContainsString('href="/reminders/calendar?month=2026-10&amp;day=2026-10-15#cal-day"', $day);
        self::assertStringNotContainsString('id="cal-day"', $html, 'no panel without a day');

        $panel = self::body($browser->get('/reminders/calendar?month=2026-10&day=2026-10-15'));
        self::assertStringContainsString('id="cal-day"', $panel);
        // The heading's punctuation is ICU's and varies by ICU version: check its parts.
        self::assertMatchesRegularExpression('#id="cal-day-title">Thursday,? 15 October 2026</h2>#', $panel);
        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $title) {
            self::assertStringContainsString($title, self::section($panel, 'cal-day'));
        }
        $return = '/reminders/calendar?month=2026-10&amp;day=2026-10-15';
        self::assertStringContainsString('<input type="hidden" name="return" value="' . $return . '">', $panel);
        self::assertStringContainsString(
            'href="/reminders/new?due=2026-10-15&amp;return=%2Freminders%2Fcalendar%3Fmonth%3D2026-10%26day%3D2026-10-15"',
            $panel,
        );

        // An action from the panel comes back to it.
        $one = $this->find($app, 'One');
        $calendarDay = '/reminders/calendar?month=2026-10&day=2026-10-15';
        $back = $browser->post('/reminders/' . $one->id . '/done', ['return' => $calendarDay]);
        self::assertSame('/reminders/calendar?month=2026-10&day=2026-10-15', $back->getHeaderLine('Location'));
        self::assertSame(ReminderStatus::Done, $this->find($app, 'One')->status);
        $outside = $browser->post('/reminders/' . $one->id . '/reopen', ['return' => 'https://example.com/']);
        self::assertSame('/reminders', $outside->getHeaderLine('Location'), 'never an outside address');

        // Add reminder with the date filled in, and back to the day after saving.
        $form = self::body($browser->get('/reminders/new?due=2026-10-15&return=' . rawurlencode($calendarDay)));
        self::assertStringContainsString('name="due_on" type="date" value="2026-10-15"', $form);
        self::assertStringContainsString('name="return" value="' . $return . '"', $form);
        $saved = $browser->post('/reminders/new', [
            'vehicle_id' => (string) $golf->id,
            'title' => 'Six',
            'due_on' => '2026-10-15',
            'lead_time_days' => '7',
            'return' => '/reminders/calendar?month=2026-10&day=2026-10-15',
        ]);
        self::assertSame('/reminders/calendar?month=2026-10&day=2026-10-15', $saved->getHeaderLine('Location'));

        // Invalid values are ignored.
        foreach (['2026-02-30', 'tomorrow', '15/10/2026'] as $bad) {
            $form = self::body($browser->get('/reminders/new?due=' . rawurlencode($bad)));
            self::assertStringContainsString('name="due_on" type="date" value=""', $form, $bad);
        }
        foreach (['?month=2026-13', '?month=bogus&day=2026-02-30', '?month=2026-10&day=2026-11-01', '?day[]=1'] as $query) {
            $page = $browser->get('/reminders/calendar' . $query);
            self::assertSame(200, $page->getStatusCode(), $query);
            self::assertStringNotContainsString('id="cal-day"', self::body($page), $query);
        }
        self::assertStringContainsString('September 2026', self::body($browser->get('/reminders/calendar?month=2026-13')));
    }

    public function testOnlyWhatTheViewerMaySee(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $polo = $this->vehicle($app, 'Polo');
        $this->document($app, $golf, '2026-10-09', ComplianceType::Insurance, null, 'Golf cover');
        $this->document($app, $polo, '2026-10-10', ComplianceType::Insurance, null, 'Polo cover');
        $browser->get('/reminders');

        $viewer = $this->createMember($app, 'viewer');
        $this->createMember($app, 'stranger', displayName: 'Stranger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));

        $shared = self::body($this->browserFor($app, 'viewer')->get('/reminders/calendar?month=2026-10&day=2026-10-09'));
        self::assertStringContainsString('Golf cover', $shared);
        self::assertStringNotContainsString('Polo cover', $shared);
        self::assertStringNotContainsString('/done"', $shared, 'View access: no actions, as on the list');

        $stranger = self::body($this->browserFor($app, 'stranger')->get('/reminders/calendar?month=2026-10&day=2026-10-09'));
        self::assertStringNotContainsString('Golf cover', $stranger);
        self::assertStringNotContainsString('Polo cover', $stranger);

        // Archived vehicles are left out; the vehicle filter narrows the month.
        $filtered = self::body($browser->get('/reminders/calendar?month=2026-10&vehicle=' . $polo->id));
        self::assertStringContainsString('Polo cover', $filtered);
        self::assertStringNotContainsString('Golf cover', $filtered);
        self::assertStringContainsString('href="/reminders/calendar?vehicle=' . $polo->id . '&amp;month=2026-11"', $filtered);
        $this->service($app, VehicleService::class)->archive($this->owner($app), $polo);
        $html = self::body($browser->get('/reminders/calendar?month=2026-10'));
        self::assertStringNotContainsString('Polo cover', $html);
        self::assertStringContainsString('Golf cover', $html);
    }

    public function testRemindersOffRemovesTheCalendarItsSwitchAndWidget(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->vehicle($app);
        $settings = $this->service($app, SettingRepository::class);
        $before = self::widgetOrder(self::body($browser->get('/?customise=1')));
        self::assertContains('calendar', $before);

        $settings->save(FeatureToggles::SETTING, ['reminders' => false], SettingScope::Global);
        self::assertSame(404, $browser->get('/reminders/calendar')->getStatusCode());
        $home = self::body($browser->get('/?customise=1'));
        self::assertNotContains('calendar', self::widgetOrder($home));
        self::assertStringNotContainsString('/reminders/calendar', $home);

        $settings->save(FeatureToggles::SETTING, ['reminders' => true], SettingScope::Global);
        self::assertSame($before, self::widgetOrder(self::body($browser->get('/?customise=1'))), 'back in its place');
    }

    public function testTheWidgetMarksDaysWithOpenReminders(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $polo = $this->vehicle($app, 'Polo');
        $this->document($app, $golf, '2026-09-20', ComplianceType::Inspection, null, 'MOT');
        // The same day: one within its lead time (due), one not yet (upcoming).
        $this->manual($browser, $golf, 'Pay road tax', '2026-09-30', null, '7');
        $this->manual($browser, $polo, 'Check tyres', '2026-09-30', null, '0');
        $this->manual($browser, $golf, 'Wash the car', '2026-09-29');
        $browser->get('/reminders');
        $browser->post('/reminders/' . $this->find($app, 'Wash the car')->id . '/done');

        $html = self::section(self::body($browser->get('/')), 'widget-calendar');
        self::assertStringContainsString('September 2026', $html);
        self::assertStringContainsString('aria-label="20 September: 1 reminder, 1 overdue"', $html);
        self::assertStringContainsString('aria-label="30 September: 2 reminders, 1 due soon"', $html);
        self::assertStringContainsString('mini-cal__day--due', $html);
        self::assertStringNotContainsString('29 September', $html, 'a done reminder is not counted');
        self::assertStringContainsString('href="/reminders/calendar?month=2026-09&amp;day=2026-09-30#cal-day"', $html);
        self::assertStringContainsString('3 reminders this month, 1 overdue', $html);
        self::assertStringContainsString('aria-current="date"', $html);
        $title = 'href="/reminders/calendar?month=2026-09"';
        self::assertStringContainsString($title, self::body($browser->get('/')), 'the title link');

        // Months are links; the vehicle chip is kept, and narrows the marks.
        $narrow = self::section(self::body($browser->get('/?vehicle=' . $polo->id)), 'widget-calendar');
        self::assertStringContainsString('href="/?vehicle=' . $polo->id . '&amp;calendar=2026-10#widget-calendar"', $narrow);
        self::assertStringContainsString('href="/?vehicle=' . $polo->id . '&amp;calendar=2026-08#widget-calendar"', $narrow);
        self::assertStringContainsString('aria-label="30 September: 1 reminder, 1 upcoming"', $narrow);
        self::assertStringNotContainsString('20 September', $narrow);
        self::assertStringContainsString(
            '1 reminder this month',
            self::section(self::body($browser->get('/?vehicle=' . $polo->id)), 'widget-calendar'),
        );
        $october = self::section(self::body($browser->get('/?calendar=2026-10')), 'widget-calendar');
        self::assertStringContainsString('October 2026', $october);
        self::assertStringContainsString('No reminders this month', $october);
        $invalid = self::section(self::body($browser->get('/?calendar=nope')), 'widget-calendar');
        self::assertStringContainsString('September 2026', $invalid);

        // A layout saved before Phase 34.3 gets it appended.
        $this->service($app, SettingRepository::class)->save(DashboardLayoutStore::SETTING, [
            'order' => ['fleet', 'needs_attention', 'reminders', 'insights', 'coming_up', 'spend', 'expense_breakdown'],
            'hidden' => [],
        ], SettingScope::User, $this->owner($app)->id);
        $order = self::widgetOrder(self::body($browser->get('/?customise=1')));
        self::assertSame('calendar', $order[7], 'after the saved ones, in the default order');
    }

    public function testOneReadOfTheRemindersHoweverManyVehicles(): void
    {
        $app = $this->createApp();
        $counter = QueryCounter::install($app);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app)->id;
        $settings = $this->service($app, SettingRepository::class);

        $page = [];
        $widget = [];
        foreach ([2, 6] as $count) {
            while (count($this->service($app, VehicleService::class)->listFleet($this->owner($app))) < $count) {
                $vehicle = $this->vehicle($app, 'Model' . random_int(0, 99999));
                $this->document($app, $vehicle, '2026-10-09');
                $this->manual($browser, $vehicle, 'Check', '2026-09-30');
            }
            $browser->get('/reminders');
            $list = $counter->during(static fn () => $browser->get('/reminders'));
            $browser->get('/reminders/calendar');
            $page[$count] = $counter->during(static fn () => $browser->get('/reminders/calendar')) - $list;

            $settings->save(DashboardLayoutStore::SETTING, ['order' => [], 'hidden' => ['calendar']], SettingScope::User, $owner);
            $browser->get('/');
            $hidden = $counter->during(static fn () => $browser->get('/'));
            $settings->save(DashboardLayoutStore::SETTING, ['order' => [], 'hidden' => []], SettingScope::User, $owner);
            $browser->get('/');
            $widget[$count] = $counter->during(static fn () => $browser->get('/')) - $hidden;
        }

        self::assertSame($page[2], $page[6], 'the calendar costs the list\'s queries plus a fixed few');
        self::assertLessThanOrEqual(3, $page[2]);
        self::assertSame(0, $widget[2], 'the widget shares Upcoming reminders\' read');
        self::assertSame(0, $widget[6]);
    }

    public function testWorksBehindASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-09-20', ComplianceType::Inspection, null, 'MOT');

        // Hard refresh with the prefix stripped by the proxy.
        $html = self::body($browser->get('/reminders/calendar?month=2026-09&day=2026-09-20'));
        $id = $this->onlyReminder($app)->id;
        self::assertStringContainsString('href="/logbook/reminders/calendar?month=2026-10"', $html);
        self::assertStringContainsString('href="/logbook/reminders"', $html);
        self::assertStringContainsString('action="/logbook/reminders/' . $id . '/done"', $html);
        $return = 'name="return" value="/logbook/reminders/calendar?month=2026-09&amp;day=2026-09-20"';
        self::assertStringContainsString($return, $html);
        self::assertStringContainsString('href="/logbook/settings/reminders#calendar"', $html);
        self::assertStringContainsString('href="/logbook/reminders/new?due=2026-09-20&amp;return=', $html);

        $home = self::body($browser->get('/'));
        self::assertStringContainsString('href="/logbook/reminders/calendar?month=2026-09&amp;day=2026-09-20#cal-day"', $home);
        self::assertStringContainsString('href="/logbook/?calendar=2026-10#widget-calendar"', $home);
        self::assertStringContainsString('href="/logbook/reminders/calendar?month=2026-09"', $home);

        $return = '/logbook/reminders/calendar?month=2026-09&day=2026-09-20';
        $done = $browser->post('/logbook/reminders/' . $id . '/done', ['return' => $return]);
        self::assertSame($return, $done->getHeaderLine('Location'));
        self::assertStringNotContainsString('aria-label="20 September', self::body($browser->get('/')), 'done: no longer marked');
    }

    /**
     * Add a manual reminder through the form.
     */
    private function manual(
        TestBrowser $browser,
        Vehicle $vehicle,
        string $title,
        ?string $due,
        ?string $odometer = null,
        string $lead = '7',
    ): void {
        $response = $browser->post('/reminders/new', [
            'vehicle_id' => (string) $vehicle->id,
            'title' => $title,
            'due_on' => $due ?? '',
            'due_odometer' => $odometer ?? '',
            'lead_time_days' => $lead,
        ]);
        self::assertSame(303, $response->getStatusCode(), 'manual reminder ' . $title);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function find(App $app, string $title): Reminder
    {
        foreach ($this->reminders($app) as $reminder) {
            if ($reminder->title === $title) {
                return $reminder;
            }
        }
        self::fail('No reminder called ' . $title);
    }

    private static function label(Reminder $reminder): string
    {
        return $reminder->title !== '' ? $reminder->title : 'Insurance';
    }

    /**
     * The markup of one day of the month grid, up to the next day or the
     * end of its week.
     */
    private static function day(string $html, string $date): string
    {
        $start = strpos($html, 'id="day-' . $date . '"');
        self::assertNotFalse($start, 'day ' . $date . ' is on the page');
        preg_match('#<li (id="day-|class="cal__day)|</ol>#', $html, $next, PREG_OFFSET_CAPTURE, $start + 1);

        return substr($html, $start, ($next[0][1] ?? strlen($html)) - $start);
    }

    /**
     * The element with this id, to the end of its section.
     */
    private static function section(string $html, string $id): string
    {
        $start = strpos($html, 'id="' . $id . '"');
        self::assertNotFalse($start, $id . ' is on the page');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    /**
     * @return list<string>
     */
    private static function widgetOrder(string $html): array
    {
        preg_match_all('/<section class="widget[^"]*" id="widget-[a-z_]+" data-widget="([a-z_]+)"/', $html, $matches);

        return $matches[1];
    }
}
