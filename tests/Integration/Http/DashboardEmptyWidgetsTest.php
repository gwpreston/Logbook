<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Empty widgets (spec.md §7.8 *Empty widgets*, Phase 54, #563): a widget
 * with nothing to show shrinks to its header and one line; *Needs
 * attention* never does, and *Customise* shows every widget full size.
 * "Today" is 27 Sep 2026.
 */
final class DashboardEmptyWidgetsTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    public function testAWidgetWithNothingToShowShrinksAndNeedsAttentionDoesNot(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->vehicle($app);

        $html = self::body($browser->get('/'));
        $empty = self::emptyWidgets($html);

        foreach (['reminders', 'coming_up', 'recent_fuel', 'efficiency', 'compliance'] as $widget) {
            self::assertContains($widget, $empty, $widget . ' has nothing to show');
        }
        self::assertNotContains('needs_attention', $empty, '"Nothing needs attention" keeps its size');
        self::assertContains('true_cost', $empty);
        $trueCost = self::widget($html, 'true_cost');
        self::assertStringNotContainsString('true_cost=since_bought', $trueCost, 'an empty true cost has no switch');
        self::assertStringNotContainsString('href="/vehicles', self::widget($html, 'recent_fuel'), 'nor recent fuel its button');
        self::assertStringNotContainsString('/fuel/new', self::widget($html, 'recent_fuel'));
        self::assertNotContains('spend', $empty, 'a £0.00 figure is a figure');
    }

    public function testAWidgetWithDataIsFullSize(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-08-01T08:00:00Z', '10000', '40', '58.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10800', '45.461', '66.64');

        $empty = self::emptyWidgets(self::body($browser->get('/')));

        self::assertNotContains('recent_fuel', $empty);
        self::assertNotContains('efficiency', $empty);
        self::assertNotContains('recent_activity', $empty);
    }

    public function testCustomiseShowsEveryWidgetFullSize(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->vehicle($app);

        self::assertSame([], self::emptyWidgets(self::body($browser->get('/?customise=1'))));
    }

    private static function widget(string $html, string $id): string
    {
        self::assertSame(1, preg_match('/<section[^>]* id="widget-' . $id . '".*?<\/section>/s', $html, $m), $id);

        return $m[0];
    }

    /**
     * @return list<string> the ids of the widgets drawn as empty
     */
    private static function emptyWidgets(string $html): array
    {
        preg_match_all('/<section class="widget[^"]*\bwidget--empty\b[^"]*" id="widget-([a-z_]+)"/', $html, $m);

        return $m[1];
    }
}
