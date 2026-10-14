<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

/**
 * The order of the dashboard's widgets and which are hidden (spec.md §7.8).
 * Always holds every widget exactly once: ids that no longer exist are
 * dropped and widgets added in a later release are appended, so an old saved
 * layout never breaks the page.
 */
final readonly class DashboardLayout
{
    /**
     * @param list<DashboardWidget> $order every widget, once
     * @param list<DashboardWidget> $hidden
     */
    private function __construct(
        public array $order,
        public array $hidden,
        public bool $household = false,
    ) {
    }

    /** *Recent activity* rows on the Household layout (spec.md §7.8, decided 2026-10-14). */
    public const int HOUSEHOLD_ACTIVITY_ROWS = 10;

    /**
     * The *Household* layout (Phase 49.2): what needs attention, what is
     * coming up and what it costs; every other widget hidden, not removed.
     */
    public static function household(): self
    {
        $shown = [
            DashboardWidget::NeedsAttention,
            DashboardWidget::ComingUp,
            DashboardWidget::Spend,
            DashboardWidget::MonthlyExpenses,
            DashboardWidget::RecentActivity,
        ];
        $hidden = array_values(array_filter(
            DashboardWidget::cases(),
            static fn (DashboardWidget $w): bool => !in_array($w, $shown, true),
        ));

        return self::of(
            array_map(static fn (DashboardWidget $w): string => $w->value, $shown),
            array_map(static fn (DashboardWidget $w): string => $w->value, $hidden),
            true,
        );
    }

    public function activityRows(int $default): int
    {
        return $this->household ? self::HOUSEHOLD_ACTIVITY_ROWS : $default;
    }

    public static function default(): self
    {
        return new self(DashboardWidget::cases(), []);
    }

    /**
     * From a saved value ({"order": [...], "hidden": [...]}); anything
     * unreadable gives the default.
     */
    public static function fromArray(mixed $value): self
    {
        if (!is_array($value)) {
            return self::default();
        }

        return self::of(
            is_array($value['order'] ?? null) ? $value['order'] : [],
            is_array($value['hidden'] ?? null) ? $value['hidden'] : [],
            ($value['preset'] ?? null) === 'household',
        );
    }

    /**
     * @param array<array-key, mixed> $order widget ids, in the wanted order
     * @param array<array-key, mixed> $hidden widget ids
     */
    public static function of(array $order, array $hidden = [], bool $household = false): self
    {
        $widgets = [];
        foreach ($order as $id) {
            $widget = is_string($id) ? DashboardWidget::tryFrom($id) : null;
            if ($widget !== null && !in_array($widget, $widgets, true)) {
                $widgets[] = $widget;
            }
        }
        foreach (DashboardWidget::cases() as $widget) {
            if (!in_array($widget, $widgets, true)) {
                $widgets[] = $widget;
            }
        }

        $hiddenWidgets = [];
        foreach (DashboardWidget::cases() as $widget) {
            if (in_array($widget->value, $hidden, true)) {
                $hiddenWidgets[] = $widget;
            }
        }

        return new self($widgets, $hiddenWidgets, $household);
    }

    /**
     * @return array{order: list<string>, hidden: list<string>, preset?: string}
     */
    public function toArray(): array
    {
        $array = [
            'order' => array_map(static fn (DashboardWidget $w): string => $w->value, $this->order),
            'hidden' => array_map(static fn (DashboardWidget $w): string => $w->value, $this->hidden),
        ];
        if ($this->household) {
            $array['preset'] = 'household';
        }

        return $array;
    }

    public function isHidden(DashboardWidget $widget): bool
    {
        return in_array($widget, $this->hidden, true);
    }

    public function isDefault(): bool
    {
        return $this->toArray() === self::default()->toArray();
    }

    /**
     * One place up (-1) or down (+1); unchanged at either end.
     */
    public function move(DashboardWidget $widget, int $direction): self
    {
        $order = $this->order;
        $from = array_search($widget, $order, true);
        $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);
        if ($from === false || $to < 0 || $to >= count($order)) {
            return $this;
        }
        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        return new self(array_values($order), $this->hidden, $this->household);
    }

    public function toggle(DashboardWidget $widget): self
    {
        $hidden = $this->isHidden($widget)
            ? array_values(array_filter($this->hidden, static fn (DashboardWidget $w): bool => $w !== $widget))
            : [...$this->hidden, $widget];

        $ids = array_map(static fn (DashboardWidget $w): string => $w->value, $hidden);

        return self::of($this->toArray()['order'], $ids, $this->household);
    }

    /**
     * The same hidden widgets in a new order (drag and drop).
     *
     * @param array<array-key, mixed> $order widget ids
     */
    public function reorder(array $order): self
    {
        return self::of($order, $this->toArray()['hidden'], $this->household);
    }
}
