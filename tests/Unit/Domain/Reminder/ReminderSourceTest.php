<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Domain\Reminder;

use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Service\Forecast\ForecastSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The icon-tile colours of reminder and *Coming up* rows (spec.md §8
 * *Category colours*): a cost-category token, never a status colour.
 */
final class ReminderSourceTest extends TestCase
{
    /** @return iterable<string, array{ReminderSource, string}> */
    public static function reminderTones(): iterable
    {
        yield 'schedule' => [ReminderSource::Schedule, 'c-maint'];
        yield 'tyre' => [ReminderSource::Tyre, 'c-maint'];
        yield 'compliance' => [ReminderSource::Compliance, 'c-ins'];
        yield 'first inspection' => [ReminderSource::FirstInspection, 'c-ins'];
        yield 'finance' => [ReminderSource::Finance, 'c-other'];
        yield 'finance end' => [ReminderSource::FinanceEnd, 'c-other'];
        yield 'issue' => [ReminderSource::Issue, 'c-other'];
        yield 'part' => [ReminderSource::Part, 'c-build'];
        yield 'manual' => [ReminderSource::Manual, 'muted'];
    }

    #[DataProvider('reminderTones')]
    public function testReminderToneIsItsCostCategory(ReminderSource $source, string $tone): void
    {
        self::assertSame($tone, $source->tone());
    }

    /** @return iterable<string, array{ForecastSource, string}> */
    public static function forecastTones(): iterable
    {
        yield 'schedule' => [ForecastSource::Schedule, 'c-maint'];
        yield 'tyres' => [ForecastSource::Tyres, 'c-maint'];
        yield 'document' => [ForecastSource::Document, 'c-ins'];
        yield 'first inspection' => [ForecastSource::FirstInspection, 'c-ins'];
        yield 'finance' => [ForecastSource::Finance, 'c-other'];
        yield 'reminder' => [ForecastSource::Reminder, 'muted'];
    }

    #[DataProvider('forecastTones')]
    public function testComingUpToneIsItsCostCategory(ForecastSource $source, string $tone): void
    {
        self::assertSame($tone, $source->tone());
    }

    /** Status tokens stay out of the tiles: urgency lives in the pill. */
    public function testNoTileTakesAStatusColour(): void
    {
        $tones = [
            ...array_map(static fn (ReminderSource $s): string => $s->tone(), ReminderSource::cases()),
            ...array_map(static fn (ForecastSource $s): string => $s->tone(), ForecastSource::cases()),
        ];

        foreach ($tones as $tone) {
            self::assertNotContains($tone, ['red', 'amber', 'green', 'accent']);
        }
    }
}
