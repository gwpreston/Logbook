<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Support\InspectionRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The first MOT rule table (spec.md §7.1 *First MOT due*): one row per
 * region, nothing else.
 */
final class InspectionRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function rows(): iterable
    {
        yield 'Great Britain' => ['en_GB', 36, '2027-06-14', 'vehicle.hint.first_inspection.gb'];
        yield 'Germany' => ['de_DE', 36, '2027-06-14', 'vehicle.hint.first_inspection.three_years'];
        yield 'France' => ['fr_FR', 48, '2028-06-14', 'vehicle.hint.first_inspection.four_years'];
        yield 'Ireland' => ['en_IE', 48, '2028-06-14', 'vehicle.hint.first_inspection.four_years'];
        yield 'Italy' => ['it_IT', 48, '2028-06-14', 'vehicle.hint.first_inspection.four_years'];
        yield 'Spain' => ['es_ES', 48, '2028-06-14', 'vehicle.hint.first_inspection.four_years'];
    }

    #[DataProvider('rows')]
    public function testEachRegionHasItsRow(string $locale, int $months, string $suggested, string $hint): void
    {
        self::assertSame($months, InspectionRules::months($locale));
        self::assertSame($suggested, self::suggest($locale, '2024-06-14'));
        self::assertSame($hint, InspectionRules::hintKey($locale));
    }

    public function testTheTableHoldsNothingElse(): void
    {
        self::assertSame(['GB' => 36, 'DE' => 36, 'FR' => 48, 'IE' => 48, 'IT' => 48, 'ES' => 48], InspectionRules::MONTHS);
    }

    public function testTwentyNinthOfFebruaryClampsToTheEndOfTheMonth(): void
    {
        self::assertSame('2027-02-28', self::suggest('en_GB', '2024-02-29'));
        self::assertSame('2028-02-29', self::suggest('fr_FR', '2024-02-29'));
    }

    public function testALocaleWithNoRegionGetsNoSuggestionAndSaysSo(): void
    {
        foreach (['en', 'de'] as $locale) {
            self::assertNull(InspectionRules::months($locale), $locale);
            self::assertNull(InspectionRules::suggest($locale, self::day('2024-06-14'), self::day('2026-09-30')), $locale);
            self::assertSame('vehicle.hint.first_inspection.none', InspectionRules::hintKey($locale), $locale);
        }
    }

    public function testOtherRegionsGetNoSuggestion(): void
    {
        foreach (['en_US', 'de_AT', 'nl_NL', 'en_AU'] as $locale) {
            self::assertNull(InspectionRules::suggest($locale, self::day('2024-06-14'), self::day('2026-09-30')), $locale);
            self::assertSame('vehicle.hint.first_inspection.none', InspectionRules::hintKey($locale), $locale);
        }
    }

    public function testASuggestionInThePastIsNeverOffered(): void
    {
        self::assertNull(self::suggest('en_GB', '2023-06-14'), 'due in June 2026: done already');
        self::assertSame('2026-09-30', self::suggest('en_GB', '2023-09-30'), 'today is still to come');
        self::assertNull(InspectionRules::suggest('en_GB', null, self::day('2026-09-30')), 'no first registration');
    }

    /**
     * The suggestion on 30 Sep 2026, as Y-m-d.
     */
    private static function suggest(string $locale, string $registered): ?string
    {
        return InspectionRules::suggest($locale, self::day($registered), self::day('2026-09-30'))?->format('Y-m-d');
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
