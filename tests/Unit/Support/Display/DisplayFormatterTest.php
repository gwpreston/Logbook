<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Display;

use DateTimeImmutable;
use IntlDateFormatter;
use Logbook\Kernel;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\TranslatorFactory;
use Logbook\Support\Money\Money;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use PHPUnit\Framework\TestCase;

final class DisplayFormatterTest extends TestCase
{
    private DisplayContext $context;
    private DisplayFormatter $formatter;

    protected function setUp(): void
    {
        $settings = AppSettings::fromEnv(new Env(['APP_CURRENCY' => 'EUR', 'APP_TIMEZONE' => 'UTC']), '/app');
        $translator = TranslatorFactory::create(Kernel::rootDir() . '/translations', 'en', null, false);

        $this->context = new DisplayContext($settings);
        $this->formatter = new DisplayFormatter($this->context, $translator);
    }

    public function testDefaultsAreMetricInTheAppCurrency(): void
    {
        self::assertSame('12,346 km', $this->formatter->distance(12345.6));
        self::assertSame('45.5 L', $this->formatter->volume('45.500'));
        self::assertSame('€1,234.50', $this->formatter->money('1234.5'));
    }

    public function testMoneyUsesTheCurrencysOwnDecimals(): void
    {
        $this->prefs('en_GB', 'Europe/London');

        self::assertSame('£0.00', $this->formatter->money('0', 'GBP'));
        self::assertSame('£0.00', $this->formatter->money(Money::zero('GBP')));
        self::assertStringEndsWith('¥1,235', $this->formatter->money('1234.5', 'JPY'));
        self::assertStringEndsWith('1,234.568', $this->formatter->money('1234.5675', 'BHD'));
        self::assertSame('-£3.50', $this->formatter->money('-3.5', 'GBP'));
        self::assertSame('', $this->formatter->money(null));
    }

    public function testLocaleChangesNumberAndMoneyFormats(): void
    {
        $this->prefs('de_DE', 'Europe/Berlin');

        self::assertSame("1.234,50\u{A0}€", $this->formatter->money('1234.5', 'EUR'));
        self::assertSame('1.234,57', $this->formatter->number('1234.567'));
    }

    public function testUnitsFollowPreferences(): void
    {
        $this->prefs('en_GB', 'Europe/London', DistanceUnit::Mile, VolumeUnit::UkGallon, ConsumptionUnit::MpgUk);

        self::assertSame('62 mi', $this->formatter->distance(100));
        self::assertSame('62.14 mi', $this->formatter->distance('100.000', 2));
        self::assertSame('10 gal', $this->formatter->volume(45.4609));
        self::assertSame('58 kWh', $this->formatter->energy('58.000'));
        // 400 km on 20 L = 5 L/100km = 56.5 mpg (UK) …
        self::assertSame('56.5 mpg', $this->formatter->consumption(400, 20));

        // … but 47.0 mpg (US).
        $this->prefs('en_US', 'America/New_York', DistanceUnit::Mile, VolumeUnit::UsGallon, ConsumptionUnit::MpgUs);
        self::assertSame('47.0 mpg (US)', $this->formatter->consumption(400, 20));
        self::assertSame('10 US gal', $this->formatter->volume(37.85411784));

        $this->prefs('en', 'UTC', DistanceUnit::Kilometre, VolumeUnit::Litre, ConsumptionUnit::LitresPer100Km);
        self::assertSame('5.0 L/100 km', $this->formatter->consumption(400, 20));
        self::assertSame('', $this->formatter->consumption(0, 20));
    }

    public function testCalendarDatesAreNeverShiftedByTheTimeZone(): void
    {
        $newYear = LocalTime::parseDate('2026-01-01');

        $this->prefs('en_US', 'America/New_York');
        self::assertSame('Jan 1, 2026', $this->formatter->date($newYear));

        $this->prefs('en_GB', 'Pacific/Auckland');
        self::assertSame('1 Jan 2026', $this->formatter->date($newYear));
    }

    public function testInstantsAreShownInTheUsersZone(): void
    {
        $instant = new DateTimeImmutable('2026-01-01T03:00:00Z');

        $this->prefs('en_GB', 'America/New_York');
        self::assertSame('31 Dec 2025, 22:00', $this->formatter->dateTime($instant));
        self::assertSame('31 Dec 2025', $this->formatter->instantDate($instant));

        $this->prefs('en_GB', 'Europe/London');
        self::assertSame('1 Jan 2026, 03:00', $this->formatter->dateTime($instant));
        // Punctuation of the full style varies between ICU versions.
        self::assertStringContainsString('1 January 2026', $this->formatter->instantDate($instant, IntlDateFormatter::FULL));

        // BST in summer.
        self::assertSame('1 Jul 2026, 13:00', $this->formatter->dateTime(new DateTimeImmutable('2026-07-01T12:00:00Z')));
    }

    private function prefs(
        string $locale,
        string $timezone,
        DistanceUnit $distance = DistanceUnit::Kilometre,
        VolumeUnit $volume = VolumeUnit::Litre,
        ConsumptionUnit $consumption = ConsumptionUnit::LitresPer100Km,
    ): void {
        $this->context->apply(new DisplayPreferences($locale, $timezone, $distance, $volume, $consumption, 'GBP'));
    }
}
