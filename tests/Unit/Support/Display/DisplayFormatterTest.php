<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Display;

use DateTimeImmutable;
use IntlDateFormatter;
use Logbook\Domain\Fuel\EnergyKind;
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

    public function testUnitPricesFollowTheVolumeUnitWithOneExtraDecimal(): void
    {
        $this->prefs('en_GB', 'Europe/London');
        self::assertSame('£1.459/L', $this->formatter->unitPrice('1.459000', 'GBP', false));
        self::assertSame('£0.245/kWh', $this->formatter->unitPrice('0.245000', 'GBP', true));

        $this->prefs('en_US', 'America/New_York', DistanceUnit::Mile, VolumeUnit::UsGallon, ConsumptionUnit::MpgUs);
        self::assertSame('$3.499/US gal', $this->formatter->unitPrice('0.924338', 'USD', false));
        self::assertSame('$0.289/kWh', $this->formatter->unitPrice('0.289000', 'USD', true), 'kWh is never converted');
        self::assertSame('', $this->formatter->unitPrice(null, 'USD', false));
    }

    public function testChartValuesKeepTheChartsPrecision(): void
    {
        $this->prefs('en_GB', 'Europe/London');

        self::assertSame('43.0', $this->formatter->chartValue(43.0, 1));
        self::assertSame('7,214', $this->formatter->chartValue(7213.8, 0));
        self::assertSame('£1.459', $this->formatter->chartValue(1.4594, 3, 'GBP'));
        self::assertSame('£1.45', $this->formatter->chartValue(1.45, 3, 'GBP'), 'money keeps at least its own decimals');
        self::assertSame('£12', $this->formatter->chartValue(12.4, 0, 'GBP'));
        self::assertSame('', $this->formatter->chartValue(null, 1));
    }

    public function testCostPerDistance(): void
    {
        $this->prefs('en_GB', 'Europe/London', DistanceUnit::Mile);
        self::assertSame('£0.169/mi', $this->formatter->perDistance('0.105000', 'GBP'));

        $this->prefs('en_GB', 'Europe/London');
        self::assertSame('£0.105/km', $this->formatter->perDistance('0.105000', 'GBP'));
    }

    public function testEconomyPicksConsumptionOrEfficiency(): void
    {
        $this->prefs('en_GB', 'Europe/London', DistanceUnit::Mile, VolumeUnit::Litre, ConsumptionUnit::MpgUk);
        self::assertSame('40.4 mpg', $this->formatter->economy('500.000', '35.000', false));
        self::assertSame('33.6 mpg (US)', $this->formatter->consumption('500', '35', 1, ConsumptionUnit::MpgUs));
        self::assertSame('6.2 mi/kWh', $this->formatter->economy('600', '60', true));
        self::assertSame('58.3 kWh', $this->formatter->quantity('58.300', true));
        self::assertSame('35 L', $this->formatter->quantity('35.000', false));

        $this->prefs('de_DE', 'Europe/Berlin');
        self::assertSame('10,0 kWh/100 km', $this->formatter->efficiency('600', '60'));
        self::assertSame('7,0 L/100 km', $this->formatter->economy('500', '35', false));
    }

    public function testCngIsShownInKgWhateverTheVolumeUnit(): void
    {
        // A mile and UK-gallon user still sees kg and mi/kg for CNG (Phase 31).
        $this->prefs('en_GB', 'Europe/London', DistanceUnit::Mile, VolumeUnit::UkGallon, ConsumptionUnit::MpgUk);
        self::assertSame('12.4 kg', $this->formatter->quantity('12.400', EnergyKind::Gas));
        self::assertSame('12.4 kg', $this->formatter->mass('12.4'));
        // 400 km on 16 kg: 248.5 mi / 16 kg = 15.5 mi/kg.
        self::assertSame('15.5 mi/kg', $this->formatter->economy('400', '16', EnergyKind::Gas));
        self::assertSame(15.5, $this->formatter->economyValue('400', '16', EnergyKind::Gas));
        self::assertSame('£1.329/kg', $this->formatter->unitPrice('1.329', 'GBP', EnergyKind::Gas));
        // The same price for petrol follows the gallon.
        self::assertSame('£6.042/gal', $this->formatter->unitPrice('1.329', 'GBP', EnergyKind::Liquid));
        // An enum kind and the old bool agree for the other kinds.
        self::assertSame(
            $this->formatter->economy('600', '60', true),
            $this->formatter->economy('600', '60', EnergyKind::Electric),
        );
        self::assertSame($this->formatter->quantity('35', false), $this->formatter->quantity('35', EnergyKind::Liquid));

        $this->prefs('de_DE', 'Europe/Berlin');
        self::assertSame('4,0 kg/100 km', $this->formatter->economy('400', '16', EnergyKind::Gas));
        self::assertSame('', $this->formatter->gasEconomy('0', '16'));
        self::assertSame('', $this->formatter->mass(null));
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
