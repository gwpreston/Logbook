<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Dashboard;

use Logbook\Service\Dashboard\DashboardLayout;
use Logbook\Service\Dashboard\DashboardWidget;
use PHPUnit\Framework\TestCase;

final class DashboardLayoutTest extends TestCase
{
    private const array DEFAULT = ['fleet', 'reminders', 'spend', 'recent_fuel', 'efficiency', 'compliance'];

    public function testDefaultShowsEveryWidgetInTheSpecOrder(): void
    {
        $layout = DashboardLayout::default();

        self::assertSame(['order' => self::DEFAULT, 'hidden' => []], $layout->toArray());
        self::assertTrue($layout->isDefault());
        self::assertTrue(DashboardLayout::fromArray(null)->isDefault(), 'nothing saved');
        self::assertTrue(DashboardLayout::fromArray('garbage')->isDefault());
    }

    public function testASavedLayoutSurvivesUnknownAndNewWidgets(): void
    {
        $layout = DashboardLayout::fromArray([
            'order' => ['compliance', 'retired_widget', 'spend', 'compliance', 42],
            'hidden' => ['fleet', 'nope'],
        ]);

        self::assertSame(
            ['compliance', 'spend', 'fleet', 'reminders', 'recent_fuel', 'efficiency'],
            $layout->toArray()['order'],
            'unknown ids and duplicates dropped; widgets it did not know about appended',
        );
        self::assertSame(['fleet'], $layout->toArray()['hidden']);
        self::assertTrue($layout->isHidden(DashboardWidget::Fleet));
        self::assertFalse($layout->isHidden(DashboardWidget::Spend));
    }

    public function testMovingStopsAtEitherEnd(): void
    {
        $layout = DashboardLayout::default();

        self::assertSame(
            ['reminders', 'fleet', 'spend', 'recent_fuel', 'efficiency', 'compliance'],
            $layout->move(DashboardWidget::Reminders, -1)->toArray()['order'],
        );
        self::assertSame(
            ['fleet', 'reminders', 'recent_fuel', 'spend', 'efficiency', 'compliance'],
            $layout->move(DashboardWidget::Spend, 1)->toArray()['order'],
        );
        self::assertSame(self::DEFAULT, $layout->move(DashboardWidget::Fleet, -1)->toArray()['order']);
        self::assertSame(self::DEFAULT, $layout->move(DashboardWidget::Compliance, 1)->toArray()['order']);
    }

    public function testHidingAndReorderingKeepEachOther(): void
    {
        $layout = DashboardLayout::default()
            ->toggle(DashboardWidget::Efficiency)
            ->reorder(['spend', 'fleet']);

        self::assertSame(['spend', 'fleet', 'reminders', 'recent_fuel', 'efficiency', 'compliance'], $layout->toArray()['order']);
        self::assertSame(['efficiency'], $layout->toArray()['hidden']);
        self::assertFalse($layout->isDefault());

        $shown = $layout->toggle(DashboardWidget::Efficiency);
        self::assertSame([], $shown->toArray()['hidden']);
        self::assertSame($layout->toArray()['order'], $shown->toArray()['order']);
    }
}
