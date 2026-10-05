<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every amount a template shows sits inside a cost-visibility check
 * (spec.md §5 *Costs*): an `{% if %}` whose condition names `costs` (a
 * variable set from can_see_costs()) or calls can_see_costs(), or an
 * expression guarded the same way (`costs ? x|money : dash`). One entry's
 * own amount may instead be guarded by `own_amount` (set from
 * can_see_amount()) or can_see_amount(), which also shows a user the
 * amounts of their own entries (Phase 19).
 *
 * Fleet-wide figures come from services that already drop vehicles
 * without ViewCosts, so those templates are listed below with the reason.
 */
final class CostVisibilityTemplateTest extends TestCase
{
    private const string AMOUNT = '/\|\s*(money|unit_price|per_distance|per_thousand_distance)\b/';
    private const string GUARD = '/(?<![\w.])(costs|own_amount)\b|can_see_costs\(|can_see_amount\(/';

    /**
     * Templates allowed to show amounts unguarded, with the reason.
     *
     * @var array<string, string>
     */
    private const array EXCEPTIONS = [
        // Fleet figures: ReportService, OwnershipService and ComingUp leave out vehicles without ViewCosts.
        'reports/index.twig' => 'Reports: ReportService drops vehicles without ViewCosts',
        'reports/ownership.twig' => 'Ownership report: OwnershipService drops vehicles without ViewCosts',
        'macros/ownership.twig' => 'cost-of-ownership wording, called only inside a costs check or from the reports',
        'dashboard/_spend.twig' => 'fleet spend: ReportService drops vehicles without ViewCosts',
        'forecast/index.twig' => 'Coming up: ComingUp leaves amounts out for vehicles without ViewCosts',
        'macros/forecast.twig' => 'Coming up rows: ComingUp leaves amounts out for vehicles without ViewCosts',
        // Not an amount of anyone's: the formatting preview.
        'profile/index.twig' => 'the money formatting preview',
        // Entry forms: amounts being entered or chosen, on Log / Manage pages.
        'tyres/form.twig' => 'the linked service record picker on a Manage form',
        'tyres/change_edit.twig' => 'the linked service record picker on a Manage form',
        // Pages whose route itself needs ViewCosts (config/routes.php).
        'expenses/index.twig' => 'the Expenses tab with costs: without ViewCosts the Action renders expenses/without_costs.twig',
        'valuations/index.twig' => 'the Valuations page: its route needs ViewCosts',
        // Finance (Phase 29.1): every finance page answers 404 without Manage and ViewCosts (FinanceRoute).
        'finance/index.twig' => 'the finance page: FinanceRoute needs Manage and ViewCosts',
        'finance/show.twig' => 'the agreement page: FinanceRoute needs Manage and ViewCosts',
        'finance/form.twig' => 'the agreement form: FinanceRoute needs Manage and ViewCosts',
        'finance/_card.twig' => 'the overview card: FinanceService::activeView() is null without Manage and ViewCosts',
        'macros/finance.twig' => 'finance wording, called only from the finance pages and card above',
        'dashboard/_finance.twig' => 'the finance widget: FinanceService::activeView() is null without Manage and ViewCosts',
        // Trips (Phase 22): a claim is the viewer's own trips at their own rates, never a vehicle's
        // costs; cost per distance comes from BusinessMileage, which leaves it out without ViewCosts.
        'trips/claim.twig' => 'the claimant’s own claim; cost per distance only with ViewCosts (BusinessMileage)',
        'macros/trips.twig' => 'the viewer’s own claim; cost per distance only with ViewCosts (BusinessMileage)',
        'dashboard/_business_mileage.twig' => 'the viewer’s own claim for this tax year',
        // Stations (Phase 30.1): StationStats counts only the amounts the viewer may see
        // (StationVisit::amountVisible, i.e. EntryAccess::canSeeAmount), and the Fuel tab's card
        // is built only with ViewCosts (FuelLogAction::byStation).
        'stations/index.twig' => 'the stations list: averages from StationStats, visible amounts only',
        'stations/show.twig' => 'the station page: StationStats and each fill-up’s amountVisible',
        'fuel/_by_station.twig' => 'the By station card: built only with ViewCosts',
        // Fuel prices (Phase 30.2): listed prices are public, and effective costs are worked out
        // from them and the vehicle's usual fill, never from anything the viewer paid.
        'stations/near.twig' => 'Cheapest near me: listed prices and effective costs, nobody’s spending',
        'dashboard/_cheapest_fuel.twig' => 'the Cheapest fuel widget: listed prices and effective costs',
    ];

    /**
     * Single expressions allowed unguarded in a template that is otherwise checked.
     *
     * @var array<string, array<string, string>>
     */
    private const array EXPRESSION_EXCEPTIONS = [
        'macros/expenses.twig' => [
            'total.amount|money' => 'breakdown(): a report section, from ReportService (vehicles without ViewCosts dropped)',
        ],
        'vehicles/archive.twig' => [
            "(agreement.data.finalPayment ?? '0')|money(currency)" => 'the agreement block: ArchiveVehicleAction passes '
                . 'finance only from FinanceService::archiveAgreement(), null without Manage and ViewCosts',
        ],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        $root = dirname(__DIR__, 3) . '/templates';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'twig') {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                yield $relative => [$relative];
            }
        }
    }

    #[DataProvider('templates')]
    public function testEveryAmountIsInsideACostsCheck(string $template): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/' . $template);
        $unguarded = self::unguardedAmounts($source);

        if (isset(self::EXCEPTIONS[$template])) {
            self::assertNotSame([], array_merge($unguarded, self::guardedAmounts($source)), sprintf(
                '%s is listed as an exception but shows no amount any more: remove it from EXCEPTIONS.',
                $template,
            ));

            return;
        }

        $allowed = self::EXPRESSION_EXCEPTIONS[$template] ?? [];
        $unguarded = array_values(array_filter($unguarded, static function (string $entry) use ($allowed): bool {
            foreach (array_keys($allowed) as $fragment) {
                if (str_contains($entry, $fragment)) {
                    return false;
                }
            }

            return true;
        }));

        self::assertSame([], $unguarded, sprintf(
            "Amounts outside a can_see_costs() / costs check in %s (line: expression):\n%s",
            $template,
            implode("\n", $unguarded),
        ));
    }

    /**
     * A `costs` variable only counts as a guard because it comes from the policy.
     */
    #[DataProvider('templates')]
    public function testACostsVariableComesFromCanSeeCosts(string $template): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/' . $template);
        preg_match_all('/\{%-?\s*set\s+costs\s*=\s*(.*?)\s*-?%\}/s', $source, $sets);

        foreach ($sets[1] as $value) {
            self::assertStringContainsString('can_see_costs(', $value, $template . ': set costs = ' . $value);
        }
        preg_match_all('/\{%-?\s*set\s+own_amount\s*=\s*(.*?)\s*-?%\}/s', $source, $own);
        foreach ($own[1] as $value) {
            self::assertStringContainsString('can_see_amount(', $value, $template . ': set own_amount = ' . $value);
        }
        $this->addToAssertionCount(1);
    }

    public function testTheScannerFollowsNesting(): void
    {
        $source = <<<'TWIG'
            {% if costs %}{{ a|money }}{% for x in y %}{{ x|money }}{% endfor %}{% else %}{{ b|money }}{% endif %}
            {{ costs ? c|money : '—' }}
            {% if feature_enabled('fuel') %}{% if can_see_costs(vehicle) %}{{ d|unit_price(currency) }}{% endif %}
            {{ e|money }}{% endif %}
            {% for x in y %}{% else %}{{ f|per_distance }}{% endfor %}
            {% if section.costs is empty %}{{ g|money }}{% endif %}
            TWIG;

        self::assertSame(
            ['1: b|money', '4: e|money', '5: f|per_distance', '6: g|money'],
            self::unguardedAmounts($source),
        );
    }

    /**
     * @return list<string> "line: expression" of each unguarded amount
     */
    private static function unguardedAmounts(string $source): array
    {
        return self::scan($source)[0];
    }

    /**
     * @return list<string>
     */
    private static function guardedAmounts(string $source): array
    {
        return self::scan($source)[1];
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private static function scan(string $source): array
    {
        $pairs = ['if', 'for', 'macro', 'block', 'apply', 'embed', 'with', 'autoescape', 'spaceless', 'verbatim'];
        /** @var list<array{tag: string, guarded: bool}> $stack */
        $stack = [];
        $unguarded = [];
        $guarded = [];

        preg_match_all('/\{([%{])-?\s*(.*?)\s*-?[%}]\}/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $isTag = $match[1][0] === '%';
            $body = $match[2][0];
            $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;

            if ($isTag) {
                $name = strtok($body, " \t\n") ?: '';
                if ($name === 'set' && !str_contains($body, '=')) {
                    $stack[] = ['tag' => 'set', 'guarded' => false];
                    continue;
                }
                if (in_array($name, $pairs, true)) {
                    $stack[] = ['tag' => $name, 'guarded' => $name === 'if' && preg_match(self::GUARD, $body) === 1];
                    continue;
                }
                if ($name === 'elseif' || $name === 'else') {
                    $top = array_key_last($stack);
                    if ($top !== null && $stack[$top]['tag'] === 'if') {
                        $stack[$top]['guarded'] = $name === 'elseif' && preg_match(self::GUARD, $body) === 1;
                    }
                    continue;
                }
                if (str_starts_with($name, 'end')) {
                    array_pop($stack);
                    continue;
                }
            }

            if (preg_match(self::AMOUNT, $body) !== 1) {
                continue;
            }
            $inCheck = array_filter($stack, static fn (array $frame): bool => $frame['guarded']) !== [];
            $entry = $line . ': ' . trim((string) preg_replace('/\s+/', ' ', $body));
            if ($inCheck || self::guardsItself($body)) {
                $guarded[] = $entry;
            } else {
                $unguarded[] = $entry;
            }
        }

        return [$unguarded, $guarded];
    }

    /**
     * `costs ? x|money : dash`, or an amount built only when can_see_costs() holds.
     */
    private static function guardsItself(string $expression): bool
    {
        $amount = preg_match(self::AMOUNT, $expression, $found, PREG_OFFSET_CAPTURE) === 1 ? $found[0][1] : 0;
        $before = substr($expression, 0, $amount);

        return preg_match('/((?<![\w.])costs\b|can_see_costs\([^)]*\))\s*(\?|and\b)/', $before) === 1;
    }
}
