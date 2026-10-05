<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Assets;

use Logbook\Action\Log\LogKind;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Dashboard\DashboardWidget;
use Logbook\Service\History\HistoryChip;
use Logbook\Service\History\Milestone;
use Logbook\Service\Insights\InsightKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every icon an enum names is in the vendored sprite (bin/vendor-assets.mjs);
 * a missing one renders as a blank space.
 */
final class IconSpriteTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function icons(): iterable
    {
        $enums = [
            LogKind::class, Location::class, ComplianceType::class, CostGroup::class,
            ExpenseCategory::class, Feature::class, MaintenanceCategory::class,
            OdometerSource::class, TyreChangeKind::class, VehicleType::class,
            DashboardWidget::class, HistoryChip::class, Milestone::class, InsightKind::class,
            IncidentType::class,
        ];
        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                $icon = $case->icon();
                yield $enum . '::' . $case->name => [$icon];
            }
        }
    }

    /**
     * The vehicle tabs' icons (spec.md §8 *Vehicle header*), from the header template.
     *
     * @return iterable<string, array{string}>
     */
    public static function tabIcons(): iterable
    {
        $header = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/vehicles/_header.twig');
        // `icon: 'name'`, or `icon: test ? 'one' : 'other'`.
        preg_match_all("/icon: (?:[^,']*\\? )?'([a-z_]+)'(?: : '([a-z_]+)')?/", $header, $matches);
        foreach (array_filter(array_unique([...$matches[1], ...$matches[2]])) as $icon) {
            yield 'tab ' . $icon => [$icon];
        }
    }

    #[DataProvider('icons')]
    #[DataProvider('tabIcons')]
    public function testIconIsInTheSprite(string $icon): void
    {
        foreach (['assets/vendor/icons.svg', 'public/assets/vendor/icons.svg'] as $sprite) {
            $svg = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $sprite);
            self::assertStringContainsString('id="' . $icon . '"', $svg, $sprite);
        }
    }
}
