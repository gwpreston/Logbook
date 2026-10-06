<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Dashboard;

use Logbook\Service\Dashboard\DashboardLayout;
use Logbook\Service\Dashboard\DashboardWidget;
use PHPUnit\Framework\TestCase;

final class DashboardLayoutTest extends TestCase
{
    private const array DEFAULT = [
        'needs_attention', 'reminders', 'calendar', 'insights', 'coming_up', 'spend', 'expense_breakdown', 'monthly_expenses',
        'recent_fuel', 'fleet', 'efficiency', 'compliance', 'mileage', 'recent_activity', 'business_mileage', 'finance',
        'cheapest_fuel', 'true_cost',
    ];

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
            [
                'compliance', 'spend', 'needs_attention', 'reminders', 'calendar', 'insights', 'coming_up', 'expense_breakdown',
                'monthly_expenses', 'recent_fuel', 'fleet', 'efficiency', 'mileage', 'recent_activity', 'business_mileage',
                'finance', 'cheapest_fuel', 'true_cost',
            ],
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
            [
                'needs_attention', 'reminders', 'calendar', 'insights', 'spend', 'coming_up', 'expense_breakdown',
                'monthly_expenses', 'recent_fuel', 'fleet', 'efficiency', 'compliance', 'mileage', 'recent_activity',
                'business_mileage', 'finance', 'cheapest_fuel',
                'true_cost',
            ],
            $layout->move(DashboardWidget::Spend, -1)->toArray()['order'],
        );
        self::assertSame(
            [
                'needs_attention', 'reminders', 'calendar', 'insights', 'coming_up', 'expense_breakdown', 'spend',
                'monthly_expenses', 'recent_fuel', 'fleet', 'efficiency', 'compliance', 'mileage', 'recent_activity',
                'business_mileage', 'finance', 'cheapest_fuel',
                'true_cost',
            ],
            $layout->move(DashboardWidget::Spend, 1)->toArray()['order'],
        );
        self::assertSame(self::DEFAULT, $layout->move(DashboardWidget::NeedsAttention, -1)->toArray()['order']);
        self::assertSame(self::DEFAULT, $layout->move(DashboardWidget::TrueCost, 1)->toArray()['order']);
    }

    public function testHidingAndReorderingKeepEachOther(): void
    {
        $layout = DashboardLayout::default()
            ->toggle(DashboardWidget::Efficiency)
            ->reorder(['spend', 'fleet']);

        self::assertSame(
            [
                'spend', 'fleet', 'needs_attention', 'reminders', 'calendar', 'insights', 'coming_up', 'expense_breakdown',
                'monthly_expenses', 'recent_fuel', 'efficiency', 'compliance', 'mileage', 'recent_activity',
                'business_mileage', 'finance', 'cheapest_fuel', 'true_cost',
            ],
            $layout->toArray()['order'],
        );
        self::assertSame(['efficiency'], $layout->toArray()['hidden']);
        self::assertFalse($layout->isDefault());

        $shown = $layout->toggle(DashboardWidget::Efficiency);
        self::assertSame([], $shown->toArray()['hidden']);
        self::assertSame($layout->toArray()['order'], $shown->toArray()['order']);
    }
}
